<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAttempt;
use App\Models\MissingParcelInvestigation;
use App\Models\SlaClock;
use App\Services\ExceptionOversightService;
use App\Services\Logistics\LogisticsEligibilityService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ExceptionOversightController extends Controller
{
    use Concerns\EncryptsSensitiveHistory;

    public function index(Request $request, ExceptionOversightService $service)
    {
        $filters = $request->validate(['kind' => ['nullable', Rule::in(array_keys(ExceptionOversightService::SOURCES))],
            'status' => ['nullable', 'in:open,resolved,all'], 'page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        $openInvestigations = Schema::hasTable('missing_parcel_investigations')
            ? MissingParcelInvestigation::query()
                ->where('status', 'investigating')
                ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('logistics_company_id', app(LogisticsEligibilityService::class)->managedCompanies($user)->select('id')))
                ->count()
            : 0;
        $breachedClocks = Schema::hasTable('sla_clocks')
            ? SlaClock::query()
                ->where('status', 'running')
                ->where('breach_at', '<=', now())
                ->when(! $user->isAdmin(), fn ($q) => $q->whereIn('logistics_company_id', app(LogisticsEligibilityService::class)->managedCompanies($user)->select('id')))
                ->count()
            : 0;

        $data = [
            'exceptions' => $service->queue($user, $filters),
            'filters' => $filters,
            'sourceGaps' => $service->sourceGaps($user),
            'monitoringSummary' => [
                'open_investigations' => $openInvestigations,
                'breached_clocks' => $breachedClocks,
            ],
        ];

        $this->encryptSensitiveHistory($request);

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : Inertia::render('Governance/Exceptions', $data);
    }

    public function show(Request $request, string $kind, int $id, ExceptionOversightService $service)
    {
        $data = ['exception' => $service->detail($request->user(), $kind, $id), 'requestToken' => (string) Str::uuid()];

        $this->encryptSensitiveHistory($request);

        return $request->expectsJson() ? response()->json($data)->header('Cache-Control', 'private, no-store') : Inertia::render('Governance/ExceptionDetail', $data);
    }

    public function decide(Request $request, string $kind, int $id, ExceptionOversightService $service)
    {
        try {
            $decision = $service->decide($request->user(), $kind, $id, $request->all());
        } catch (DomainException $error) {
            return $request->expectsJson() ? response()->json(['message' => $error->getMessage()], 409) : back()->withErrors(['exception' => $error->getMessage()]);
        }

        return $request->expectsJson() ? response()->json(['reference' => $decision->reference])->header('Cache-Control', 'private, no-store') : back()->with('success', 'Exception decision recorded.');
    }

    public function proof(Request $request, int $id, ExceptionOversightService $service)
    {
        $service->detail($request->user(), 'attempt', $id, false);
        $attempt = DeliveryAttempt::findOrFail($id);
        abort_unless(preg_match('/\Adelivery-attempt-proofs\/[A-Za-z0-9._-]+\z/', $attempt->proof_path) === 1 && Storage::disk('local')->exists($attempt->proof_path), 404);
        abort_unless(hash_equals($attempt->proof_hash, hash('sha256', Storage::disk('local')->get($attempt->proof_path))), 409, 'The retained attempt proof does not match its original evidence.');

        return Storage::disk('local')->response($attempt->proof_path, 'attempt-proof', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
