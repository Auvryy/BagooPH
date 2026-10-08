<?php

namespace App\Services;

use App\Models\User;
use App\Rules\BirthDate;
use Illuminate\Database\QueryException;
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
        $applications = app(ApplicationValidationService::class);
        $validated = $applications->normalize($validated, $subject->role);
        $paths = [];
        try {
            DB::transaction(function () use ($subject, $validated, $buyerUpload, &$paths) {
                $user = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($user->role, ['buyer', 'seller', 'courier', 'logistics'], true), 403);
                $birthDates = app(BirthDateEligibility::class);
                $adult = $birthDates->requiresAdult($user->role);
                $birthdayProvided = array_key_exists('birthday', $validated);
                $categoryProvided = array_key_exists('root_category_id', $validated);
                $applications = app(ApplicationValidationService::class);
                $provided = array_intersect_key($validated, array_flip($applications->fields($user->role)));
                if (isset($provided['email'])) {
                    if (strcasecmp($provided['email'], $user->email) !== 0) {
                        throw ValidationException::withMessages(['email' => 'Your original sign-in email stays with your account.']);
                    }
                    $provided['email'] = $user->email;
                }
                $pendingCorrection = ! $buyerUpload && $user->isKycPending()
                    && (($adult && $birthdayProvided) || array_diff(array_keys($provided), ['birthday']));
                $allowedStates = $buyerUpload ? ['none', 'rejected', 'pending_approval'] : ['rejected'];
                if ($pendingCorrection) {
                    $allowedStates[] = 'pending_approval';
                }
                if ($user->isBuyer()) {
                    app(BuyerAccessService::class)->requireApplication($user);
                    $allowedStates = ['none', 'pending_approval', 'rejected'];
                }
                abort_unless(in_array($user->kyc_status, $allowedStates, true) && (! $buyerUpload || $user->isBuyer()), 409, 'Only an unreviewed buyer ID, pending application correction, or rejected application can be submitted.');
                $this->decisions->lockProfile($user);
                if ($categoryProvided) {
                    abort_unless($user->isSeller(), 403);
                    if (! $user->shop) {
                        throw ValidationException::withMessages(['root_category_id' => 'The original shop application is missing. Contact support before correcting its category.']);
                    }
                    app(MasterCategoryService::class)->requireEligible((int) $validated['root_category_id']);
                }
                $categoryChanged = $categoryProvided && (int) $validated['root_category_id'] !== (int) $user->shop->root_category_id;
                $birthday = $birthdayProvided ? $validated['birthday'] : $user->birthday?->toDateString();
                Validator::make(['birthday' => $birthday], ['birthday' => [$adult ? 'required' : 'nullable', new BirthDate($adult)]])->validate();
                $current = $applications->values($user);
                $changes = array_filter($provided, fn ($value, $field) => $value !== ($current[$field] ?? null), ARRAY_FILTER_USE_BOTH);
                if ($pendingCorrection && ! $changes && ! $user->isBuyer()) {
                    $field = $categoryProvided ? 'root_category_id' : ($birthdayProvided ? 'birthday' : 'application');
                    throw ValidationException::withMessages([$field => 'Change an application detail to submit a correction.']);
                }
                $allowedFiles = match ($user->role) {
                    'buyer' => ['id_document'],
                    'seller' => ['id_document', 'business_permit'],
                    'courier' => ['id_document', 'driver_license', 'or_cr_document'],
                    'logistics' => ['business_permit', 'franchise_document'],
                };
                $uploads = array_filter(array_intersect_key($validated, array_flip($allowedFiles)), fn ($upload) => $upload instanceof UploadedFile);
                if (! $uploads && ! $changes) {
                    throw ValidationException::withMessages(['documents' => 'Upload a corrected document or change an application detail.']);
                }
                if ($user->isLogistics() && ! $user->logisticsCompany) {
                    throw ValidationException::withMessages(['documents' => 'The company application is missing. Contact support before resubmitting.']);
                }
                if ($user->isCourier() && ! $user->courierProfile) {
                    throw ValidationException::withMessages(['documents' => 'The courier application is missing. Contact support before resubmitting.']);
                }
                Validator::make($applications->normalize(array_replace($current, $provided), $user->role), $applications->rules($user->role, $user))->validate();
                $paths = $this->documents->storeUploads($uploads);
                if ($categoryChanged) {
                    $user->shop->update(['root_category_id' => (int) $validated['root_category_id']]);
                }
                if ($user->isSeller() && $user->shop) {
                    $shopUpdates = [];
                    foreach (['shop_name' => 'name', 'shop_phone' => 'phone', 'shop_address' => 'address', 'shop_city' => 'city'] as $field => $column) {
                        if (array_key_exists($field, $changes)) {
                            $shopUpdates[$column] = $changes[$field];
                        }
                    }
                    if ($shopUpdates) {
                        $user->shop->update($shopUpdates);
                    }
                }
                if ($user->isCourier() && $user->courierProfile) {
                    $user->courierProfile->update(array_intersect_key($changes, array_flip(['vehicle_type', 'plate_number', 'license_number'])));
                }
                $updates = array_intersect_key($changes, array_flip(ApplicationValidationService::ACCOUNT_FIELDS))
                    + array_intersect_key($paths, array_flip(['id_document_path', 'business_permit_path', 'driver_license_path', 'or_cr_path']));
                if ($user->isLogistics()) {
                    $accreditation = $user->logisticsCompany->accreditation_details ?? [];
                    $companyUpdates = [];
                    foreach (['company_name' => 'name', 'company_code' => 'code', 'company_email' => 'contact_email', 'company_phone' => 'contact_phone', 'company_address' => 'address'] as $field => $column) {
                        if (array_key_exists($field, $changes)) {
                            $companyUpdates[$column] = $changes[$field];
                        }
                    }
                    foreach (['operating_province', 'franchise_number', 'fleet_size', 'vehicle_types'] as $field) {
                        if (array_key_exists($field, $changes)) {
                            $accreditation[$field] = $changes[$field];
                        }
                    }
                    foreach (['business_permit_path', 'franchise_document_path'] as $field) {
                        if (isset($paths[$field])) {
                            $accreditation[$field] = $paths[$field];
                        }
                    }
                    $user->logisticsCompany->update($companyUpdates + ['accreditation_details' => $accreditation]);
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
            if ($exception instanceof QueryException && in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
                $detail = $exception->errorInfo[2] ?? '';
                if (str_contains($detail, 'users_email') || str_contains($detail, 'users.email')) {
                    throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
                }
                if (str_contains($detail, 'logistics_companies_code') || str_contains($detail, 'logistics_companies.code')) {
                    throw ValidationException::withMessages(['company_code' => 'This company code is already registered.']);
                }
            }
            throw $exception;
        }
        $subject->refresh();
    }
}
