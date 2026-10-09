<?php

namespace App\Services\Courier;

use App\Models\CodAccount;
use App\Models\User;
use App\Rules\ApplicationText;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodCashViewService;
use Brick\Math\BigInteger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RiderCashService
{
    public function __construct(private readonly CodCashService $cash, private readonly CodCashViewService $views) {}

    public function list(Request $request): array
    {
        $query = $this->cash->scoped($request->user());
        $held = '0';
        $unresolved = 0;
        foreach ((clone $query)->cursor() as $account) {
            $source = $this->views->present($account, $request->user(), false);
            foreach ($source['holders'] as $holder) {
                if ($holder['user_id'] === $request->user()->id && $holder['stage'] === 'rider') {
                    $held = $this->add($held, (string) $holder['amount_cents']);
                }
            }
            $unresolved += $source['status'] === 'difference' ? 1 : 0;
        }
        $filters = Validator::make($request->query(), ['q' => ['sometimes', 'string', new ApplicationText('search', 1, 100)]])->validate();
        if (isset($filters['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->whereHas('delivery', fn ($parcel) => $parcel->whereRaw("tracking_number LIKE ? ESCAPE '!'", [$term]));
        }
        $page = RiderApiInput::page($request);
        $rows = $query->orderByDesc('id')->paginate((int) $page['per_page'], ['*'], 'page', (int) $page['page']);

        return ['items' => $rows->getCollection()->map(fn ($account) => $this->resource($account, $request->user(), false))->all(),
            'summary' => ['own_held_cents' => $held, 'accounts_with_unresolved_difference' => $unresolved, 'basis' => 'all_owned_cash_journals'],
            'earnings' => ['available' => false, 'confirmed_cents' => null],
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    public function resource(CodAccount $account, User $actor, bool $details = true): array
    {
        $source = $this->views->present($account, $actor, $details);
        $last = $account->events()->latest('sequence')->firstOrFail();
        $ownHeld = '0';
        $holders = array_map(function ($holder) use ($actor, &$ownHeld) {
            if ($holder['user_id'] === $actor->id && $holder['stage'] === 'rider') {
                $ownHeld = $this->add($ownHeld, (string) $holder['amount_cents']);
            }

            return ['user_id' => (string) $holder['user_id'], 'name' => $holder['name'], 'stage' => $holder['stage'],
                'amount_cents' => (string) $holder['amount_cents'], 'restricted' => $holder['restricted']];
        }, $source['holders']);
        $pending = $source['pending'];
        $remitted = '0';
        foreach ($account->events()->where('event_type', 'handover_received')->where('from_user_id', $actor->id)->cursor() as $event) {
            $remitted = $this->add($remitted, (string) $event->amount_cents);
        }
        $data = ['id' => (string) $account->id, 'reference' => $source['reference'], 'delivery_id' => (string) $account->delivery_id,
            'tracking_number' => $source['tracking_number'], 'order_number' => $source['order_number'], 'source_kind' => $source['source_kind'],
            'status' => $source['status'], 'version' => (string) $source['version'], 'source_event_reference' => $last->reference,
            'hub_name' => $source['hub_name'], 'company_name' => $source['company_name'], 'own_held_cents' => $ownHeld, 'own_remitted_cents' => $remitted,
            'holders' => $holders, 'recorded_at' => RiderApiInput::time($last->created_at),
            'pending_handover' => $pending ? ['reference' => $pending['reference'], 'amount_cents' => (string) $pending['amount_cents'],
                'holder_id' => (string) $pending['holder_id'], 'recipient_id' => (string) $pending['recipient_id'],
                'holder_name' => $pending['holder_name'], 'recipient_name' => $pending['recipient_name']] : null,
            'can_offer' => $ownHeld !== '0' && ! $pending && $source['status'] !== 'reconciled',
            'can_receive' => false, 'can_reconcile' => false, 'earnings' => ['available' => false, 'confirmed_cents' => null]];
        foreach (['expected_cents', 'product_subtotal_cents', 'discount_cents', 'shipping_cents', 'collected_cents', 'reconciled_cents', 'excess_cents'] as $field) {
            $data[$field] = (string) $source[$field];
        }
        if ($details) {
            $data['recipients'] = array_map(fn ($user) => ['id' => (string) $user['id'], 'name' => $user['name']], $source['recipients']['hub']);
            $data['history'] = array_map(function ($event) {
                $data = array_intersect_key($event, array_flip(['reference', 'event_type', 'actor_role', 'actor_name', 'from_stage', 'to_stage', 'provenance', 'evidence_reference', 'reason', 'source_reference']));
                foreach (['sequence', 'amount_cents', 'expected_cents', 'received_cents', 'discrepancy_cents', 'from_user_id', 'to_user_id'] as $key) {
                    $data[$key] = $event[$key] === null ? null : (string) $event[$key];
                }
                $data['recorded_at'] = RiderApiInput::time($event['created_at']);

                return $data;
            }, $source['history']);
            $data['discrepancies'] = collect($source['discrepancies'])->map(fn ($entry, $reference) => [
                'source_reference' => $reference, 'difference_cents' => (string) $entry['difference_cents'],
                'holder_id' => (string) $entry['holder_id'], 'resolved_by' => $entry['resolved_by'],
            ])->values()->all();
        }

        return $data;
    }

    private function add(string $left, string $right): string
    {
        return (string) BigInteger::of($left)->plus($right);
    }
}
