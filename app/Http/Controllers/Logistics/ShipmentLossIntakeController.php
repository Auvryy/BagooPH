<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\BuyerReturnParcel;
use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\ShipmentFinancialPlan;
use App\Models\ShipmentLossFinding;
use App\Models\User;
use App\Rules\AsciiPositiveInteger;
use App\Services\Finance\ShipmentLossCustody;
use App\Services\Finance\ShipmentLossReconciliationService;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Logistics\LogisticsEligibilityService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ShipmentLossIntakeController extends Controller
{
    private function hub(Request $request, ShipmentFinancialPlan $plan): LogisticsHub
    {
        $data = Validator::make($request->only('hub_id'), ['hub_id' => ['required', new AsciiPositiveInteger, 'integer']])->validate();
        $actor = User::findOrFail($request->user()->id);
        $hub = LogisticsHub::eligible()->whereKey($data['hub_id'])->where('logistics_company_id', $plan->logistics_company_id)->firstOrFail();
        abort_unless($actor->isLogistics() && $actor->canAccessPortal() && $actor->email_verified_at
            && app(LogisticsEligibilityService::class)->canScan($actor, $hub), 403);
        $parcel = $plan->buyer_return_case_id ? BuyerReturnParcel::where('buyer_return_case_id', $plan->buyer_return_case_id)->firstOrFail()
            : Delivery::whereKey($plan->delivery_id)->firstOrFail();
        $reconciledReleased = app(ShipmentLossReconciliationService::class)->active($plan)?->status === 'released';
        abort_unless($plan->kind === 'transit_loss'
            && ($reconciledReleased || app(ShipmentLossCustody::class)->authority($parcel)?->id === $plan->id), 404);

        return $hub;
    }

    public function show(Request $request, ShipmentFinancialPlan $plan)
    {
        $hub = $this->hub($request, $plan);
        abort_unless(Schema::hasTable('shipment_loss_findings'), 409, 'Apply the additive found-parcel migration before recording quarantine.');
        $finding = ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->first();
        $blocker = null;
        if (! $finding) {
            try {
                app(ShipmentLossSource::class)->assertApproved($plan);
            } catch (HttpExceptionInterface $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
                $blocker = 'The original loss records changed. Ask for independent reconciliation before a new intake.';
            }
        }
        $assessment = $finding ? app(ShipmentLossReconciliationService::class)->assessment($plan) : null;
        $record = $assessment['reconciliation'] ?? null;
        $safeReconciliation = is_array($record) ? [
            'id' => $record['id'] ?? null,
            'reference' => $record['reference'] ?? null,
            'outcome_path' => $record['outcome_path'] ?? null,
            'outcome_label' => $record['outcome_label'] ?? null,
            'status' => $record['status'] ?? null,
            'approved_at' => $record['approved_at'] ?? null,
            'released_at' => $record['released_at'] ?? null,
        ] : null;
        $stale = (bool) ($assessment['stale'] ?? false);
        $canRelease = $finding !== null
            && $finding->hub_id === $hub->id
            && ($record['status'] ?? null) === 'approved'
            && ! $stale
            && in_array($record['outcome_path'] ?? '', $assessment['eligible_paths'] ?? [], true);
        $releaseBlocker = null;
        if ($finding && ($record['status'] ?? null) !== 'released') {
            if ($finding->hub_id !== $hub->id) {
                $releaseBlocker = 'This parcel was placed under quarantine at another facility.';
            } elseif (! $record || ($record['status'] ?? null) === 'invalidated') {
                $releaseBlocker = 'An independent admin must approve found-parcel reconciliation before releasing quarantine.';
            } elseif ($stale) {
                $releaseBlocker = 'Source financial or facility facts changed after approval. Ask an admin to invalidate and re-approve reconciliation before releasing custody.';
            } elseif (! $canRelease) {
                $releaseBlocker = $assessment['blocked_reasons'][$record['outcome_path'] ?? ''] ?? 'The approved reconciliation path is currently blocked.';
            }
        }

        return Inertia::render('Hub/LossFoundIntake', ['intake' => ['id' => $plan->id,
            'barcode' => $plan->snapshot['source']['barcode'], 'manifest_reference' => $plan->snapshot['source']['manifest_reference'],
            'scope' => $plan->buyer_return_case_id ? 'Buyer return' : 'Original parcel',
            'hub' => $hub->only(['id', 'name', 'code']), 'version' => (int) $plan->events()->max('sequence'),
            'can_intake' => ! $finding && ! $blocker, 'blocker' => $blocker,
            'finding' => $finding ? ['reference' => $finding->reference, 'recorded_at' => $finding->created_at->toIso8601String(),
                'at_selected_hub' => $finding->hub_id === $hub->id,
                'proof_url' => $finding->hub_id === $hub->id ? '/loss-found/'.$plan->id.'/proof?hub_id='.$hub->id : null] : null],
            'release' => $finding ? [
                'can_release' => $canRelease,
                'blocker' => $releaseBlocker,
                'stale' => $stale,
                'reconciliation' => $safeReconciliation,
            ] : null,
            'requestToken' => (string) Str::uuid()])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, ShipmentFinancialPlan $plan, ShipmentLossCustody $custody)
    {
        try {
            $hub = $this->hub($request, $plan);
            $data = Validator::make($request->all(), ['proof' => ['required', 'file', 'max:5120']])->validate();
            $finding = $custody->intake($request->user(), $plan, $hub, $request->except('proof'), $data['proof']);
        } catch (ValidationException $error) {
            return $request->expectsJson() ? response()->json(['message' => 'Check the found-parcel intake.', 'errors' => $error->errors()], 422)
                : back()->withErrors($error->errors());
        } catch (ModelNotFoundException $error) {
            throw $error;
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [409, 422, 503], true)) {
                throw $error;
            }

            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], $error->getStatusCode())
                : back()->withErrors(['quarantine' => $error->getMessage()]);
        } catch (Throwable $error) {
            Log::error('Found-parcel intake result could not be confirmed.', ['exception_class' => $error::class]);
            $message = 'Could not confirm this intake. Check the saved quarantine record before retrying the same update.';

            return $request->expectsJson() ? response()->json(['message' => $message], 503) : back()->withErrors(['quarantine' => $message]);
        }

        return $request->expectsJson() ? response()->json(['finding' => ['reference' => $finding->reference]], 201)->header('Cache-Control', 'private, no-store')
            : redirect('/loss-found/'.$plan->id.'?hub_id='.$hub->id)->with('success', 'Found parcel placed on hold for reconciliation.');
    }

    public function release(Request $request, ShipmentFinancialPlan $plan, ShipmentLossCustody $custody)
    {
        $hub = $this->hub($request, $plan);
        try {
            $record = $custody->release($request->user(), $plan, $hub, $request->except('_token'));
        } catch (ValidationException|HttpExceptionInterface $error) {
            if ($request->expectsJson() || ($error instanceof HttpExceptionInterface && $error->getStatusCode() !== 409)) {
                throw $error;
            }

            return back()->withErrors($error instanceof ValidationException ? $error->errors() : ['release' => $error->getMessage()]);
        }

        return $request->expectsJson()
            ? response()->json(['reconciliation' => $record->only(['id', 'reference', 'outcome_path', 'status', 'released_at'])])
                ->header('Cache-Control', 'private, no-store')
            : back()->with('success', 'Physical quarantine was released according to the approved reconciliation.');
    }

    public function proof(Request $request, ShipmentFinancialPlan $plan)
    {
        $hub = $this->hub($request, $plan);
        $finding = ShipmentLossFinding::where('shipment_financial_plan_id', $plan->id)->where('hub_id', $hub->id)->firstOrFail();
        abort_unless(str_starts_with($finding->proof_path, 'shipment-loss-findings/') && ! str_contains($finding->proof_path, '..')
            && $finding->proof_bytes > 0 && $finding->proof_bytes <= 5242880 && Storage::disk('local')->exists($finding->proof_path)
            && Storage::disk('local')->size($finding->proof_path) === $finding->proof_bytes
            && hash_equals($finding->proof_hash, hash_file('sha256', Storage::disk('local')->path($finding->proof_path))), 404);

        return response()->file(Storage::disk('local')->path($finding->proof_path), ['Content-Type' => $finding->proof_mime,
            'Content-Disposition' => 'attachment; filename="found-parcel-evidence"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
