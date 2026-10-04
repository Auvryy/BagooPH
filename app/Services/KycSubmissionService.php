<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class KycSubmissionService
{
    public function __construct(private VerificationDocumentService $documents, private KycDecisionService $decisions) {}

    public function submit(User $subject, array $validated, bool $buyerUpload = false): void
    {
        $paths = [];
        try {
            DB::transaction(function () use ($subject, $validated, $buyerUpload, &$paths) {
                $user = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($user->role, ['buyer', 'seller', 'courier', 'logistics'], true), 403);
                $allowedStates = $buyerUpload ? ['none', 'rejected', 'pending_approval'] : ['rejected'];
                abort_unless(in_array($user->kyc_status, $allowedStates, true) && (! $buyerUpload || $user->isBuyer()), 409, 'Only an unreviewed buyer ID or rejected application can be resubmitted.');
                $this->decisions->lockProfile($user);
                $allowedFiles = match ($user->role) {
                    'buyer' => ['id_document'],
                    'seller' => ['id_document', 'business_permit'],
                    'courier' => ['id_document', 'driver_license', 'or_cr_document'],
                    'logistics' => ['business_permit', 'franchise_document'],
                };
                $uploads = array_filter(array_intersect_key($validated, array_flip($allowedFiles)));
                if (! $uploads) {
                    throw ValidationException::withMessages(['documents' => 'Upload at least one corrected verification document.']);
                }
                if ($user->isLogistics() && ! $user->logisticsCompany) {
                    throw ValidationException::withMessages(['documents' => 'The company application is missing. Contact support before resubmitting.']);
                }
                $paths = $this->documents->storeUploads($uploads);
                $updates = array_intersect_key($paths, array_flip(['id_document_path', 'business_permit_path', 'driver_license_path', 'or_cr_path']));
                if ($user->isLogistics()) {
                    $accreditation = $user->logisticsCompany->accreditation_details ?? [];
                    foreach (['business_permit_path', 'franchise_document_path'] as $field) {
                        if (isset($paths[$field])) {
                            $accreditation[$field] = $paths[$field];
                        }
                    }
                    $user->logisticsCompany->update(['accreditation_details' => $accreditation]);
                }
                $user->update($updates + [
                    'kyc_status' => 'pending_approval', 'kyc_feedback' => null,
                    'kyc_submitted_at' => now(), 'kyc_reviewed_at' => null,
                ]);
            });
            $subject->refresh();
        } catch (Throwable $exception) {
            // Old reviewed files stay private; only files from this failed submission are removed.
            Storage::disk('local')->delete(array_values($paths));
            throw $exception;
        }
    }
}
