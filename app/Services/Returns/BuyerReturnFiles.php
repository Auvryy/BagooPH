<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuyerReturnFiles
{
    public function inspect(UploadedFile $file, string $field): array
    {
        $image = $file->isValid() ? @getimagesize($file->getRealPath()) : false;
        $extension = match ($image['mime'] ?? null) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null,
        };
        $bytes = $file->isValid() ? filesize($file->getRealPath()) : 0;
        if (! $extension || $bytes < 1 || $bytes > 5242880) {
            throw ValidationException::withMessages([$field => 'Choose a real JPG, PNG or WebP photo up to 5 MB.']);
        }

        return ['extension' => $extension, 'mime' => $image['mime'], 'bytes' => $bytes,
            'content_hash' => hash_file('sha256', $file->getRealPath())];
    }

    public function store(UploadedFile $file, array $info): string
    {
        $path = $file->storeAs('buyer-return-evidence', Str::uuid().'.'.$info['extension'], 'local');
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 503, 'The return photo could not be saved. Try again.');

        return $path;
    }

    public function discardUnused(array $paths): void
    {
        foreach ($paths as $path) {
            if (! BuyerReturnEvidence::where('path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
