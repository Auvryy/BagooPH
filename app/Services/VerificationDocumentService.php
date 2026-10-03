<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class VerificationDocumentService
{
    public const FIELDS = [
        'id' => 'id_document_path',
        'permit' => 'business_permit_path',
        'license' => 'driver_license_path',
        'orcr' => 'or_cr_path',
        'franchise' => 'franchise_document_path',
    ];

    private const INPUTS = [
        'id_document' => 'id_document_path',
        'business_permit' => 'business_permit_path',
        'driver_license' => 'driver_license_path',
        'or_cr_document' => 'or_cr_path',
        'franchise_document' => 'franchise_document_path',
    ];

    public function storeUploads(array $validated): array
    {
        $paths = [];
        foreach (self::INPUTS as $input => $field) {
            $file = $validated[$input] ?? null;
            if (! $file instanceof UploadedFile) {
                continue;
            }

            try {
                $path = $file->store('kyc_documents', ['disk' => 'local', 'visibility' => 'private']);
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('Verification document storage failed.');
                }
                $paths[$field] = $path;
            } catch (Throwable) {
                Storage::disk('local')->delete(array_values($paths));
                throw ValidationException::withMessages([
                    $input => 'We could not save this verification document. Please try again.',
                ]);
            }
        }

        return $paths;
    }

    public function links(User $owner): array
    {
        $links = [];
        foreach (self::FIELDS as $kind => $field) {
            $path = $this->storedPath($owner, $kind);
            $extension = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : null;
            $links[$field] = $extension && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)
                ? route('verification-documents.show', ['user' => $owner->id, 'document' => "$kind.$extension"], false)
                : null;
        }

        return $links;
    }

    public function show(User $actor, User $owner, string $document): StreamedResponse
    {
        abort_unless(
            ($actor->id === $owner->id && in_array($actor->status, ['active', 'pending_approval'], true))
            || ($actor->isAdmin() && $actor->status === 'active'),
            403,
        );
        abort_unless(preg_match('/\A(id|permit|license|orcr|franchise)\.(jpg|jpeg|png|webp|pdf)\z/', $document, $matches) === 1, 404);
        $path = $this->storedPath($owner, $matches[1]);
        abort_unless(
            $path && preg_match('/\Akyc_documents\/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp|pdf)\z/i', $path) === 1
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === $matches[2]
            && Storage::disk('local')->exists($path),
            404,
        );

        return Storage::disk('local')->response($path, $document, [
            'Content-Type' => match ($matches[2]) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
            },
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function storedPath(User $owner, string $kind): ?string
    {
        if ($kind === 'franchise') {
            return $owner->logisticsCompany?->accreditation_details['franchise_document_path'] ?? null;
        }

        return isset(self::FIELDS[$kind]) ? $owner->getRawOriginal(self::FIELDS[$kind]) : null;
    }
}
