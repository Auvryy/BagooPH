<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class RegistrationDocument implements ValidationRule
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MIME_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('Choose a valid verification document.');

            return;
        }
        $size = $value->getSize();
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            $fail('Choose a non-empty document no larger than 5 MB (5,242,880 bytes).');

            return;
        }
        $expected = self::MIME_TYPES[strtolower($value->getClientOriginalExtension())] ?? null;
        if ($expected === null || $value->getMimeType() !== $expected) {
            $fail('Choose a JPG, PNG, WEBP or PDF document whose contents match its file type.');

            return;
        }
        if ($expected !== 'application/pdf') {
            $dimensions = @getimagesize($value->getPathname());
            if ($dimensions === false || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 20000000) {
                $fail('Choose a readable image up to 10,000 pixels per side and 20 million pixels in total, or use a PDF.');
            }
        }
    }
}
