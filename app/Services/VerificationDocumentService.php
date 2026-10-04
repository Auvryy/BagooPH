<?php

namespace App\Services;

use App\Models\KycDecision;
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

    public function links(User $owner, ?KycDecision $decision = null): array
    {
        $links = [];
        foreach (self::FIELDS as $kind => $field) {
            $path = $decision ? ($decision->submission['documents'][$kind]['path'] ?? null) : $this->storedPath($owner, $kind);
            $extension = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : null;
            $links[$field] = $extension && in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)
                ? route('verification-documents.show', array_filter(['user' => $owner->id, 'document' => "$kind.$extension", 'decision' => $decision?->id]), false)
                : null;
        }

        return $links;
    }

    public function authorize(User $actor, User $owner): void
    {
        $actor = User::findOrFail($actor->id);
        abort_unless(
            ($actor->id === $owner->id && in_array($actor->status, ['active', 'pending_approval'], true))
            || ($actor->isAdmin() && $actor->status === 'active'),
            403,
        );
    }

    public function show(User $actor, User $owner, string $document, ?KycDecision $decision = null): StreamedResponse
    {
        $this->authorize($actor, $owner);
        abort_unless(preg_match('/\A(id|permit|license|orcr|franchise)\.(jpg|jpeg|png|webp|pdf)\z/', $document, $matches) === 1, 404);
        abort_if($decision && $decision->user_id !== $owner->id, 404);
        $path = $decision ? ($decision->submission['documents'][$matches[1]]['path'] ?? null) : $this->storedPath($owner, $matches[1]);
        abort_unless(
            $path && preg_match('/\Akyc_documents\/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp|pdf)\z/i', $path) === 1
            && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === $matches[2]
            && Storage::disk('local')->exists($path),
            404,
        );
        if ($decision) {
            abort_unless(hash_file('sha256', Storage::disk('local')->path($path)) === ($decision->submission['documents'][$matches[1]]['sha256'] ?? null), 409, 'The reviewed document is no longer intact.');
        }

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

    public function evidence(User $owner): array
    {
        $evidence = [];
        foreach (self::FIELDS as $kind => $field) {
            $path = $this->storedPath($owner, $kind);
            $document = ['path' => $path, 'sha256' => null, 'mime' => null, 'valid' => false];
            if ($path && preg_match('/\Akyc_documents\/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp|pdf)\z/i', $path, $matches) === 1 && Storage::disk('local')->exists($path)) {
                $localPath = Storage::disk('local')->path($path);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);
                $expected = match (strtolower($matches[1])) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'pdf' => 'application/pdf',
                };
                $document = [
                    'path' => $path,
                    'sha256' => hash_file('sha256', $localPath),
                    'mime' => $mime,
                    'valid' => $mime === $expected && filesize($localPath) > 0 && filesize($localPath) <= 5120 * 1024,
                ];
            }
            $evidence[$kind] = $document;
        }

        return $evidence;
    }

    private function storedPath(User $owner, string $kind): ?string
    {
        if ($kind === 'franchise') {
            return $owner->logisticsCompany?->accreditation_details['franchise_document_path'] ?? null;
        }

        return isset(self::FIELDS[$kind]) ? $owner->getRawOriginal(self::FIELDS[$kind]) : null;
    }
}
