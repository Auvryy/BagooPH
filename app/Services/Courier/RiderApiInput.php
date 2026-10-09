<?php

namespace App\Services\Courier;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RiderApiInput
{
    public static function id(string $value): int
    {
        abort_unless(preg_match('/\A[1-9][0-9]*\z/', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);

        return (int) $value;
    }

    public static function body(Request $request, array $rules): array
    {
        $extra = array_diff(array_keys($request->all()), array_keys($rules));
        if ($extra) {
            self::error('VALIDATION_FAILED', 'Unexpected fields were supplied.', 422,
                array_fill_keys($extra, ['This field is not accepted.']));
        }

        return Validator::make($request->all(), $rules)->validate();
    }

    public static function page(Request $request): array
    {
        return Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ])->validate() + ['page' => 1, 'per_page' => 20];
    }

    public static function error(string $code, string $message, int $status, array $errors = []): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message', 'errors'), $status));
    }

    public static function time($time): ?string
    {
        return $time?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
