<?php

namespace App\Services\Returns;

use App\Models\BuyerReturnMoneyEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuyerReturnMoneyFiles
{
    public function inspect(UploadedFile $file): array
    {
        $bytes = $file->isValid() ? filesize($file->getRealPath()) : 0;
        $image = $bytes > 0 && $bytes <= 5242880 ? @getimagesize($file->getRealPath()) : false;
        $mime = $image['mime'] ?? null;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null,
        };
        if (! $extension && $bytes > 0 && $bytes <= 5242880) {
            $contents = file_get_contents($file->getRealPath());
            if ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) === 'application/pdf' && preg_match('/\A%PDF-1\.[0-9]/', $contents)
                && preg_match('/%%EOF\s*\z/', $contents)) {
                $extension = 'pdf';
                $mime = 'application/pdf';
            }
        }
        if (! $extension) {
            throw ValidationException::withMessages(['proof' => 'Choose a real JPG, PNG, WebP or PDF receipt up to 5 MB.']);
        }

        return ['extension' => $extension, 'proof_mime' => $mime, 'proof_bytes' => $bytes,
            'proof_hash' => hash_file('sha256', $file->getRealPath())];
    }

    public function store(UploadedFile $proof, array $info): string
    {
        $path = $proof->storeAs('buyer-return-money', Str::uuid().'.'.$info['extension'], 'local');
        abort_unless($path && Storage::disk('local')->exists($path), 503, 'The payment evidence could not be saved.');

        return $path;
    }

    public function discardUnused(array $paths): void
    {
        foreach ($paths as $path) {
            if (! BuyerReturnMoneyEvent::where('proof_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
