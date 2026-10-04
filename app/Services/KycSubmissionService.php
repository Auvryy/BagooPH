<?php

namespace App\Services;

use App\Models\User;
use App\Rules\BirthDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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
                $birthDates = app(BirthDateEligibility::class);
                $adult = $birthDates->requiresAdult($user->role);
                $birthdayProvided = array_key_exists('birthday', $validated);
                $pendingCorrection = ! $buyerUpload && $adult && $user->isKycPending() && $birthdayProvided;
                $allowedStates = $buyerUpload ? ['none', 'rejected', 'pending_approval'] : ['rejected'];
                if ($pendingCorrection) {
                    $allowedStates[] = 'pending_approval';
                }
                abort_unless(in_array($user->kyc_status, $allowedStates, true) && (! $buyerUpload || $user->isBuyer()), 409, 'Only an unreviewed buyer ID, pending birth-date correction, or rejected application can be submitted.');
                $this->decisions->lockProfile($user);
                $birthday = $birthdayProvided ? $validated['birthday'] : $user->birthday?->toDateString();
                Validator::make(['birthday' => $birthday], ['birthday' => [$adult ? 'required' : 'nullable', new BirthDate($adult)]])->validate();
                $birthdayChanged = $birthdayProvided && $birthday !== $user->birthday?->toDateString();
                if ($pendingCorrection && ! $birthdayChanged) {
                    throw ValidationException::withMessages(['birthday' => 'Change your birth date to submit a correction.']);
                }
                $allowedFiles = match ($user->role) {
                    'buyer' => ['id_document'],
                    'seller' => ['id_document', 'business_permit'],
                    'courier' => ['id_document', 'driver_license', 'or_cr_document'],
                    'logistics' => ['business_permit', 'franchise_document'],
                };
                $uploads = array_filter(array_intersect_key($validated, array_flip($allowedFiles)), fn ($upload) => $upload instanceof UploadedFile);
                if (! $uploads && ! $birthdayChanged) {
                    throw ValidationException::withMessages(['documents' => 'Upload at least one corrected document or change your birth date.']);
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
                    'birthday' => $birthday, 'age' => $birthDates->age($birthday),
                    'kyc_status' => 'pending_approval', 'kyc_feedback' => null,
                    'kyc_submitted_at' => now(), 'kyc_reviewed_at' => null,
                ]);
            });
        } catch (Throwable $exception) {
            // Old reviewed files stay private; only files from this failed submission are removed.
            Storage::disk('local')->delete(array_values($paths));
            throw $exception;
        }
        $subject->refresh();
    }
}
