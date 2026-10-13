<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Normalizer;

class ApplicationIdentityService
{
    public static function shopNameKey(string $name): string
    {
        $name = Normalizer::normalize($name, Normalizer::FORM_KC);
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return hash('sha256', Normalizer::normalize(mb_convert_case($name, MB_CASE_FOLD, 'UTF-8'), Normalizer::FORM_KC));
    }

    public static function conflictErrors(QueryException $exception): array
    {
        if (! in_array($exception->errorInfo[0] ?? null, ['23000', '23505'], true)) {
            return [];
        }
        $message = $exception->errorInfo[2] ?? '';
        if (str_contains($message, 'users_mobile_unique') || str_contains($message, 'UNIQUE constraint failed: users.phone')) {
            return ['phone' => 'This mobile number is already registered. Use a different number.'];
        }
        if (str_contains($message, 'shops_name_key_unique') || str_contains($message, 'UNIQUE constraint failed: shops.name_key')) {
            return ['shop_name' => 'This shop name is already taken. Choose a different name.'];
        }

        return [];
    }
}
