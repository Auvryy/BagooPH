<?php

namespace App\Services\Courier;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourierProofService
{
    public function store(UploadedFile $file, bool $attempt = false): string
    {
        $image = @getimagesize($file->getRealPath());
        $extension = match ($image['mime'] ?? null) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null
        };
        if (! $extension || filesize($file->getRealPath()) > 5242880) {
            throw ValidationException::withMessages(['proof_image_file' => ['Choose an actual JPEG, PNG or WebP image up to 5 MB.']]);
        }
        $directory = $attempt ? 'delivery-attempt-proofs' : 'delivery-proofs';
        $path = $file->storeAs($directory, (string) Str::uuid().'.'.$extension, 'local');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 503, 'The proof image could not be saved.');

        return $path;
    }

    public function hash(?string $path): string
    {
        [$disk, $file] = $this->source($path);
        if (! Storage::disk($disk)->exists($file)) {
            throw new DomainException('Upload a proof of delivery image before recording handoff.');
        }
        $contents = Storage::disk($disk)->get($file);
        if ($disk === 'local' && ! @getimagesizefromstring($contents)) {
            throw new DomainException('The proof must contain an actual image.');
        }

        return hash('sha256', $contents);
    }

    public function source(?string $path): array
    {
        if (is_string($path) && preg_match('/\Adelivery-(?:attempt-)?proofs\/[A-Za-z0-9][A-Za-z0-9._-]*\z/', $path) === 1) {
            return ['local', $path];
        }
        // Retained historical callers may still refer to an original public proof. New writers never use this form.
        if (is_string($path) && preg_match('/\A\/storage\/delivery-proofs\/[A-Za-z0-9][A-Za-z0-9._-]*\z/', $path) === 1) {
            return ['public', substr($path, strlen('/storage/'))];
        }
        throw new DomainException('Upload a proof of delivery image before recording handoff.');
    }

    public function discardUnused(?string $path): void
    {
        if ($path && ! DeliveryCheckpoint::where('proof_image', $path)->exists()
            && ! DeliveryAttempt::where('proof_path', $path)->exists() && ! Delivery::where('proof_image', $path)->exists()) {
            [$disk, $file] = $this->source($path);
            Storage::disk($disk)->delete($file);
        }
    }
}
