<?php

namespace App\Services;

use App\Models\AccountClosure;
use App\Models\IdentityCorrectionDecision;
use App\Models\IdentityCorrectionRequest;
use App\Models\KycDecision;
use App\Models\ProductModerationDecision;
use App\Models\RestrictionDecision;
use App\Models\ShopReviewDecision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class GovernanceHistoryService
{
    public const SOURCES = [
        'kyc_review' => ['model' => KycDecision::class, 'label' => 'Account application review'],
        'shop_review' => ['model' => ShopReviewDecision::class, 'label' => 'Shop review'],
        'restriction' => ['model' => RestrictionDecision::class, 'label' => 'Account or resource activity'],
        'identity_correction' => ['model' => IdentityCorrectionDecision::class, 'label' => 'Reviewed identity correction'],
        'account_closure' => ['model' => AccountClosure::class, 'label' => 'Account closure'],
        'product_moderation' => ['model' => ProductModerationDecision::class, 'label' => 'Product compliance'],
    ];

    private const SNAPSHOT_KEYS = ['id', 'user_id', 'seller_id', 'buyer_id', 'shop_id', 'category_id', 'root_category_id',
        'role', 'status', 'kyc_status', 'kyc_reviewed_at', 'kyc_feedback', 'birthday', 'name', 'email', 'phone',
        'address', 'city', 'province', 'postal_code', 'sex', 'age', 'identity_version', 'restriction_version',
        'first_name', 'last_name', 'middle_name', 'shop_name', 'shop_phone', 'shop_address', 'shop_city', 'or_cr_status',
        'license_number', 'company_name', 'company_code',
        'moderation_version', 'compliance_restricted', 'review_status', 'review_version', 'review_decision_id', 'reviewed_at', 'review_feedback',
        'eligible', 'reviewed_scope', 'logistics_company_id', 'hub_id', 'assigned_hub_id', 'vehicle_id',
        'assigned_driver_id', 'is_active', 'is_available', 'tier', 'vehicle_type', 'plate_number', 'closed_at',
        'product', 'shop', 'subject', 'account', 'identity', 'values', 'courier', 'companies', 'shops', 'handlers',
        'owner', 'company', 'hub', 'member', 'profile', 'shop_eligible', 'category_eligible', 'resource_status',
        'work', 'order_id', 'order_number', 'order_status', 'parcel', 'courier_id', 'assigned_rider_id',
        'current_hub_id', 'payment_method', 'payment_status', 'number', 'total', 'price', 'stock', 'outcome'];

    public function __construct(private readonly ResourceRestrictionService $resources, private readonly VerificationDocumentService $documents) {}

    public function actor(User $actor): User
    {
        return $this->resources->currentActor($actor);
    }

    public function sourceOptions(User $actor): array
    {
        $actor = $this->actor($actor);

        return collect(self::SOURCES)->filter(fn ($source, $key) => $actor->isAdmin() || $key === 'restriction')
            ->map(fn ($source, $key) => ['value' => $key, 'label' => $source['label']])->values()->all();
    }

    public function subjectTypes(User $actor): array
    {
        return $this->actor($actor)->isAdmin() ? ['account', 'shop', 'company', 'hub', 'handler', 'fleet', 'product'] : ['hub', 'handler', 'fleet'];
    }

    public function validate(User $actor, array $input): array
    {
        return Validator::make($input, [
            'source' => ['nullable', Rule::in(array_column($this->sourceOptions($actor), 'value'))],
            'subject_type' => ['nullable', 'required_with:subject_id', Rule::in($this->subjectTypes($actor))],
            'subject_id' => 'nullable|integer|min:1', 'actor_id' => 'nullable|integer|min:1',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...(! empty($input['from']) ? ['after_or_equal:from'] : [])],
            'page' => 'nullable|integer|min:1|max:1000000',
            'per_page' => 'prohibited', 'export' => 'prohibited',
        ])->validate();
    }

    private function events(User $actor): Builder
    {
        $actor = $this->actor($actor);
        $restriction = DB::table('restriction_decisions')->selectRaw("'restriction' as source, subject_type, subject_id, actor_id, decided_at as occurred_at, id");
        if (! $actor->isAdmin()) {
            return $restriction->where(function ($scope) use ($actor) {
                foreach ($this->resources->types($actor) as $type) {
                    $scope->orWhere(fn ($typeScope) => $typeScope->where('subject_type', $type)
                        ->whereIn('subject_id', $this->resources->query($actor, $type)->select('id')));
                }
            });
        }
        $queries = [
            DB::table('kyc_decisions')->selectRaw("'kyc_review' as source, 'account' as subject_type, user_id as subject_id, reviewer_id as actor_id, reviewed_at as occurred_at, id"),
            DB::table('shop_review_decisions')->selectRaw("'shop_review' as source, 'shop' as subject_type, shop_id as subject_id, reviewer_id as actor_id, reviewed_at as occurred_at, id"),
            DB::table('identity_correction_decisions as decisions')->join('identity_correction_requests as requests', 'requests.id', '=', 'decisions.correction_request_id')
                ->selectRaw("'identity_correction' as source, 'account' as subject_type, requests.user_id as subject_id, decisions.actor_id, decisions.decided_at as occurred_at, decisions.id"),
            DB::table('account_closures')->selectRaw("'account_closure' as source, 'account' as subject_type, subject_id, actor_id, closed_at as occurred_at, id"),
            DB::table('product_moderation_decisions')->selectRaw("'product_moderation' as source, 'product' as subject_type, product_id as subject_id, actor_id, decided_at as occurred_at, id"),
        ];
        foreach ($queries as $query) {
            $restriction->unionAll($query);
        }

        return $restriction;
    }

    public function search(User $actor, array $input): LengthAwarePaginator
    {
        $actor = $this->actor($actor);
        $filters = $this->validate($actor, $input);
        $query = DB::query()->fromSub($this->events($actor), 'events');
        foreach (['source', 'subject_type', 'subject_id', 'actor_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay()->utc()->format('Y-m-d H:i:s'));
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<', CarbonImmutable::parse($filters['to'], 'Asia/Manila')->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s'));
        }

        return $query->orderByDesc('occurred_at')->orderBy('source')->orderByDesc('id')->paginate(15, ['*'], 'page', $filters['page'] ?? 1)->appends($filters)
            ->through(fn ($event) => $this->project($actor, $event->source, self::SOURCES[$event->source]['model']::findOrFail($event->id)));
    }

    public function detail(User $actor, string $source, int $id): array
    {
        $actor = $this->actor($actor);
        abort_unless(isset(self::SOURCES[$source]), 404);
        abort_unless($actor->isAdmin() || $source === 'restriction', 403);
        $model = self::SOURCES[$source]['model']::findOrFail($id);
        if (! $actor->isAdmin()) {
            abort_unless($source === 'restriction', 403);
            $this->resources->find($actor, $model->subject_type, $model->subject_id);
        }

        return $this->project($actor, $source, $model);
    }

    public function safeSnapshot(array $snapshot): array
    {
        $result = [];
        foreach ($snapshot as $key => $value) {
            if (is_int($key) || in_array($key, self::SNAPSHOT_KEYS, true)) {
                $result[$key] = is_array($value) ? $this->safeSnapshot($value) : $value;
            }
        }

        return $result;
    }

    private function evidenceLinks(string $source, Model $model): array
    {
        if ($source === 'kyc_review') {
            $owner = User::find($model->user_id);

            return $owner ? collect($this->documents->links($owner, $model))->filter()->map(fn ($url, $field) => ['kind' => array_search($field, VerificationDocumentService::FIELDS, true), 'url' => $url])->values()->all() : [];
        }
        $evidence = match ($source) {
            'shop_review' => $model->submission['documents'] ?? [],
            'identity_correction' => IdentityCorrectionRequest::findOrFail($model->correction_request_id)->evidence,
            default => [],
        };
        $links = [];
        foreach ($evidence as $kind => $file) {
            $extension = strtolower(pathinfo($file['path'] ?? '', PATHINFO_EXTENSION));
            if (! isset(VerificationDocumentService::FIELDS[$kind]) || ! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)
                || ($source === 'shop_review' && ! in_array($kind, ['id', 'permit'], true))) {
                continue;
            }
            $url = $source === 'shop_review'
                ? route('shop-verification-documents.show', ['shop' => $model->shop_id, 'document' => $kind.'.'.$extension, 'decision' => $model->id], false)
                : route('identity-correction-documents.show', ['correction' => $model->correction_request_id, 'document' => $kind.'.'.$extension], false);
            $links[] = ['kind' => $kind, 'url' => $url];
        }

        return $links;
    }

    private function project(User $actor, string $source, Model $model): array
    {
        $request = $source === 'identity_correction' ? IdentityCorrectionRequest::findOrFail($model->correction_request_id) : null;
        [$subjectType, $subjectId] = match ($source) {
            'kyc_review' => ['account', $model->user_id], 'shop_review' => ['shop', $model->shop_id],
            'restriction' => [$model->subject_type, $model->subject_id],
            'identity_correction' => ['account', $request->user_id], 'account_closure' => ['account', $model->subject_id],
            'product_moderation' => ['product', $model->product_id],
        };
        $before = $model->before_state ?? [];
        $after = $source === 'account_closure' ? ['outcome' => $model->outcome, 'closed_at' => $model->closed_at->toISOString()] : ($model->after_state ?? []);
        if (! $actor->isAdmin()) {
            $before = ['subject' => $before['subject'] ?? []];
            $after = ['subject' => $after['subject'] ?? []];
        }
        $name = match ($source) {
            'kyc_review' => $model->submission['account']['name'] ?? null,
            'shop_review' => $model->submission['shop']['name'] ?? null,
            'restriction' => $before['subject']['name'] ?? null,
            'identity_correction' => $request->source['values']['name'] ?? null,
            'account_closure' => $model->subject_name,
            'product_moderation' => $before['product']['name'] ?? null,
        };
        $actorId = $model->reviewer_id ?? $model->actor_id;
        $actorName = $model->reviewer_name ?? $model->actor_name;
        $time = $model->reviewed_at ?? $model->decided_at ?? $model->closed_at;
        $prior = $source === 'product_moderation' && $model->prior_decision_id
            ? ['source' => 'product_moderation', 'id' => $model->prior_decision_id] : null;
        if ($request && ($request->provenance['kyc_decision_id'] ?? null)) {
            $prior = ['source' => 'kyc_review', 'id' => $request->provenance['kyc_decision_id']];
        }
        if ($source === 'shop_review' && $model->kyc_decision_id) {
            $prior = ['source' => 'kyc_review', 'id' => $model->kyc_decision_id];
        } elseif ($source === 'shop_review' && $model->identity_correction_request_id) {
            $correction = IdentityCorrectionDecision::where('correction_request_id', $model->identity_correction_request_id)->where('action', 'approve')->first();
            $prior = $correction ? ['source' => 'identity_correction', 'id' => $correction->id] : null;
        }

        return [
            'id' => $model->id, 'source' => $source, 'source_label' => self::SOURCES[$source]['label'],
            'subject' => ['type' => $subjectType, 'id' => $subjectId, 'name' => $name,
                'name_provenance' => $name ? 'recorded_snapshot' : 'name_not_recorded'],
            'actor' => ['id' => $actorId, 'name' => $actorName, 'role' => $model->reviewer_role ?? $model->actor_role],
            'action' => $model->action ?? $model->decision ?? $model->outcome,
            'reason' => $model->reason, 'occurred_at' => $time->toISOString(),
            'before' => $this->safeSnapshot($before), 'after' => $this->safeSnapshot($after),
            'prior_decision' => $prior,
            'documents' => $actor->isAdmin() ? $this->evidenceLinks($source, $model) : [],
            'context_url' => $actor->isAdmin() && $subjectType === 'account' ? '/admin/users/'.$subjectId.'/context' : null,
            'detail_url' => '/governance-history/'.$source.'/'.$model->id,
        ];
    }
}
