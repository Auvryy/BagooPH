<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class ReadableChatImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $information = $value instanceof UploadedFile ? @getimagesize($value->getRealPath()) : false;
        if (! $information || ! in_array($information[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            $fail('We could not read this photo. Choose a JPG, PNG or WEBP image.');
        }
    }
}
