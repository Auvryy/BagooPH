<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\KycDecisionService;
use App\Services\VerificationDocumentService;
use Illuminate\Support\Facades\Storage;

trait InteractsWithKycReviews
{
    private bool $kycStorageInitialized = false;

    protected function addKycEvidence(User $user): void
    {
        if (! $this->kycStorageInitialized) {
            Storage::fake('local');
            $this->kycStorageInitialized = true;
        }
        $required = match ($user->role) {
            'buyer' => ['id'], 'seller' => ['id', 'permit'],
            'courier' => ['id', 'license', 'orcr'], 'logistics' => ['permit'],
        };
        $paths = [];
        foreach ($required as $kind) {
            $path = 'kyc_documents/'.$user->id.'-'.$kind.'.pdf';
            Storage::disk('local')->put($path, "%PDF-1.4\nBagoo {$kind} evidence for {$user->id}\n%%EOF");
            $paths[VerificationDocumentService::FIELDS[$kind]] = $path;
        }
        $user->update($paths + ['kyc_submitted_at' => now()]);
    }

    protected function kycPayload(User $user): array
    {
        $service = app(KycDecisionService::class);

        return ['review_token' => $service->token($service->submission($user->fresh())), 'evidence_confirmed' => true];
    }

    protected function inspectKycEvidence(User $admin, User $user): void
    {
        $service = app(KycDecisionService::class);
        foreach ($service->requiredDocuments($service->submission($user->fresh())) as $kind) {
            $this->actingAs($admin)->get('/verification-documents/'.$user->id.'/'.$kind.'.pdf')->assertOk();
        }
    }

    protected function prepareKycReview(User $admin, User $user): array
    {
        $this->addKycEvidence($user);
        $this->inspectKycEvidence($admin, $user);

        return $this->kycPayload($user);
    }
}
