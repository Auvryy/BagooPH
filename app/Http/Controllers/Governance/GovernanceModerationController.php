<?php

namespace App\Http\Controllers\Governance;

use App\Http\Controllers\Concerns\EncryptsSensitiveHistory;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\EnforcementAppeal;
use App\Models\EnforcementSanction;
use App\Models\FinancialPenalty;
use App\Models\LogisticsCompany;
use App\Models\ModerationSignal;
use App\Models\Review;
use App\Models\ReviewDispute;
use App\Models\ScaleDiscrepancyLog;
use App\Models\Shop;
use App\Models\SlaClock;
use App\Models\User;
use App\Services\Commerce\ReviewService;
use App\Services\Governance\EnforcementService;
use App\Services\Governance\FinancialPenaltyService;
use App\Services\Governance\ModerationSignalService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GovernanceModerationController extends Controller
{
    use EncryptsSensitiveHistory;

    public function index(Request $request, EnforcementService $enforcement): JsonResponse|Response
    {
        $user = $enforcement->assertVerifiedAdmin($request->user());
        $enforcement->expireDueSanctions();

        $sanctions = Schema::hasTable('enforcement_sanctions')
            ? EnforcementSanction::with(['subjectUser:id,name,email,role', 'issuedBy:id,name', 'resolvedBy:id,name', 'appeals.appellant:id,name'])
                ->latest('id')->limit(60)->get()
            : collect();

        $appeals = Schema::hasTable('enforcement_appeals')
            ? EnforcementAppeal::with(['sanction', 'appellant:id,name,role', 'reviewedBy:id,name'])
                ->latest('id')->limit(60)->get()
            : collect();

        $penalties = Schema::hasTable('financial_penalties')
            ? FinancialPenalty::with(['subjectUser:id,name,role', 'order:id,order_number', 'delivery:id,tracking_number', 'hub:id,name', 'proposedBy:id,name', 'approvedBy:id,name'])
                ->latest('id')->limit(60)->get()
            : collect();

        $signals = Schema::hasTable('moderation_signals')
            ? ModerationSignal::with(['subjectUser:id,name,role', 'order:id,order_number', 'delivery:id,tracking_number', 'review:id,rating,comment,moderation_status', 'reviewedBy:id,name'])
                ->latest('id')->limit(60)->get()
            : collect();

        $scaleLogs = Schema::hasTable('scale_discrepancy_logs')
            ? ScaleDiscrepancyLog::with(['order:id,order_number', 'delivery:id,tracking_number', 'shop:id,name', 'seller:id,name', 'hub:id,name', 'penalty:id,reference,status'])
                ->latest('id')->limit(60)->get()
            : collect();

        $reviewDisputes = Schema::hasTable('review_disputes')
            ? ReviewDispute::with(['review.product:id,name,slug', 'review.order:id,order_number', 'shop:id,name', 'seller:id,name', 'buyer:id,name', 'adjudicatedBy:id,name', 'appealReviewedBy:id,name'])
                ->latest('id')->limit(60)->get()
            : collect();

        $heldReviews = Schema::hasColumn('reviews', 'moderation_status')
            ? Review::with(['product:id,name,shop_id', 'buyer:id,name', 'order:id,order_number'])
                ->where('moderation_status', 'held_velocity_review')
                ->latest('id')->limit(30)->get()
            : collect();

        $breachedSlaClocks = SlaClock::with(['delivery:id,tracking_number,order_id', 'company:id,name', 'hub:id,name,province'])
            ->where('stage', 'pickup')
            ->where('status', 'breached')
            ->latest('breach_at')->limit(30)->get();

        $deliveriesForScale = Delivery::with('order:id,order_number')
            ->whereNotNull('order_id')
            ->latest('id')->limit(40)->get(['id', 'tracking_number', 'order_id', 'status']);

        $subjects = [
            'buyers' => User::where('role', 'buyer')->orderBy('id')->limit(40)->get(['id', 'name', 'email']),
            'sellers' => User::where('role', 'seller')->orderBy('id')->limit(40)->get(['id', 'name', 'email']),
            'shops' => Shop::orderBy('id')->limit(40)->get(['id', 'name', 'user_id']),
            'companies' => LogisticsCompany::orderBy('id')->limit(40)->get(['id', 'name', 'user_id']),
        ];

        $data = [
            'adminId' => $user->id,
            'sanctions' => $sanctions,
            'appeals' => $appeals,
            'penalties' => $penalties,
            'signals' => $signals,
            'scaleLogs' => $scaleLogs,
            'reviewDisputes' => $reviewDisputes,
            'heldReviews' => $heldReviews,
            'breachedSlaClocks' => $breachedSlaClocks,
            'deliveriesForScale' => $deliveriesForScale,
            'subjects' => $subjects,
            'constants' => [
                'scopes' => EnforcementSanction::SCOPES,
                'reasonCodes' => EnforcementService::REASON_CODES,
                'defaultSlaPenaltyCents' => FinancialPenaltyService::DEFAULT_SLA_PENALTY_CENTS,
            ],
        ];

        $this->encryptSensitiveHistory($request);

        return ($request->expectsJson() && ! $request->header('X-Inertia'))
            ? response()->json($data)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Governance/Moderation', $data);
    }

    public function issueSanction(Request $request, EnforcementService $service): JsonResponse|RedirectResponse
    {
        try {
            $sanction = $service->issueSanction($request->user(), $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['sanction' => $sanction], 201)
                : back()->with('success', "Enforcement record {$sanction->reference} issued.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        } catch (DomainException $e) {
            return $this->fail($request, ['enforcement' => $e->getMessage()], 422);
        }
    }

    public function resolveSanction(Request $request, EnforcementSanction $sanction, EnforcementService $service): JsonResponse|RedirectResponse
    {
        try {
            $updated = $service->resolveSanction($request->user(), $sanction, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['sanction' => $updated])
                : back()->with('success', "Enforcement record {$updated->reference} marked {$updated->status}.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function decideAppeal(Request $request, EnforcementAppeal $appeal, EnforcementService $service): JsonResponse|RedirectResponse
    {
        try {
            $updated = $service->decideAppeal($request->user(), $appeal, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['appeal' => $updated])
                : back()->with('success', "Appeal {$updated->reference} {$updated->status}.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function proposeSlaPenalty(Request $request, SlaClock $slaClock, FinancialPenaltyService $service): JsonResponse|RedirectResponse
    {
        try {
            $penalty = $service->proposeSlaPickupPenalty($request->user(), $slaClock, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['penalty' => $penalty], 201)
                : back()->with('success', "Financial penalty proposal {$penalty->reference} logged for admin review.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function recordScaleLog(Request $request, Delivery $delivery, FinancialPenaltyService $service): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'measured_weight_grams' => ['required', 'integer', 'min:1', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $log = $service->recordScaleDiscrepancy(
                $request->user(),
                $delivery,
                (int) $validated['measured_weight_grams'],
                $validated['notes'] ?? null
            );

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['scale_log' => $log], 201)
                : back()->with('success', "Scale reading {$log->reference} recorded.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function proposeScalePenalty(Request $request, ScaleDiscrepancyLog $scaleLog, FinancialPenaltyService $service): JsonResponse|RedirectResponse
    {
        try {
            $penalty = $service->proposeScalePenalty($request->user(), $scaleLog, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['penalty' => $penalty], 201)
                : back()->with('success', "Tariff discrepancy penalty {$penalty->reference} proposed.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function proposeManualPenalty(Request $request, FinancialPenaltyService $service, EnforcementService $enforcement): JsonResponse|RedirectResponse
    {
        try {
            $penalty = $service->proposeManualPenalty($request->user(), $request->all(), $enforcement);

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['penalty' => $penalty], 201)
                : back()->with('success', "Financial penalty {$penalty->reference} queued for review.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function decidePenalty(Request $request, FinancialPenalty $penalty, FinancialPenaltyService $service, EnforcementService $enforcement): JsonResponse|RedirectResponse
    {
        try {
            $updated = $service->decidePenalty($request->user(), $penalty, $request->all(), $enforcement);

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['penalty' => $updated])
                : back()->with('success', "Penalty {$updated->reference} updated to {$updated->status}.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function reviewSignal(Request $request, ModerationSignal $signal, ModerationSignalService $service, EnforcementService $enforcement): JsonResponse|RedirectResponse
    {
        try {
            $updated = $service->reviewSignal($request->user(), $signal, $request->all(), $enforcement);

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['signal' => $updated])
                : back()->with('success', "Signal {$updated->reference} marked {$updated->status}.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function adjudicateReviewDispute(Request $request, ReviewDispute $dispute, ReviewService $reviews): JsonResponse|RedirectResponse
    {
        try {
            $updated = $reviews->adjudicateDispute($request->user(), $dispute, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['dispute' => $updated])
                : back()->with('success', "Review dispute {$updated->reference} decided ({$updated->status}).");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function adjudicateReviewAppeal(Request $request, ReviewDispute $dispute, ReviewService $reviews): JsonResponse|RedirectResponse
    {
        try {
            $updated = $reviews->adjudicateAppeal($request->user(), $dispute, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['dispute' => $updated])
                : back()->with('success', "Review appeal {$updated->reference} resolved ({$updated->status}).");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    public function moderateVelocityReview(Request $request, Review $review, ReviewService $reviews): JsonResponse|RedirectResponse
    {
        try {
            $updated = $reviews->moderateVelocityHold($request->user(), $review, $request->all());

            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['review' => $updated])
                : back()->with('success', "Review #{$updated->id} moderation updated to {$updated->moderation_status}.");
        } catch (ValidationException $e) {
            return $this->fail($request, $e->errors());
        }
    }

    private function fail(Request $request, array $errors, int $status = 422): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['errors' => $errors], $status);
        }

        return back()->withErrors($errors);
    }
}
