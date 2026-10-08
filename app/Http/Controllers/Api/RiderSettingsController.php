<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountEmail;
use App\Services\AccountEmailService;
use App\Services\AccountSettingsService;
use App\Services\RiderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RiderSettingsController extends Controller
{
    private function address(string $id): AccountEmail
    {
        abort_unless(filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 404);

        return AccountEmail::findOrFail($id);
    }

    private function inputs(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->all()), $allowed);
        if ($unexpected) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed here.'));
        }
    }

    public function show(Request $request, RiderSettingsService $settings): JsonResponse
    {
        $this->inputs($request, []);

        return response()->json(['data' => $settings->snapshot($request)]);
    }

    public function profile(Request $request, RiderSettingsService $settings): JsonResponse
    {
        $this->inputs($request, ['phone', 'revision']);

        return response()->json(['data' => $settings->updateContact($request)]);
    }

    public function password(Request $request, AccountSettingsService $settings): JsonResponse
    {
        $this->inputs($request, ['current_password', 'password', 'password_confirmation']);
        $settings->changePassword($request);

        return response()->json(['data' => ['password_changed' => true, 'reauthentication_required' => true]]);
    }

    public function send(Request $request, AccountEmailService $emails): JsonResponse
    {
        $this->inputs($request, ['email', 'current_password']);
        $result = $emails->send($request);
        $status = $result['success'] ? 200 : ($result['status'] ?? 429);
        unset($result['status']);
        foreach (['cooldown', 'expires_in'] as $field) {
            if (isset($result[$field])) {
                $result[$field] = (int) ceil($result[$field]);
            }
        }

        return response()->json($result, $status);
    }

    public function confirm(Request $request, AccountEmailService $emails, RiderSettingsService $settings): JsonResponse
    {
        $this->inputs($request, ['email', 'current_password', 'code']);
        $emails->confirm($request);

        return response()->json(['data' => $settings->snapshot($request)]);
    }

    public function prefer(Request $request, string $id, AccountEmailService $emails, RiderSettingsService $settings): JsonResponse
    {
        $this->inputs($request, ['current_password']);
        $emails->manage($request, $this->address($id), remove: false);

        return response()->json(['data' => $settings->snapshot($request)]);
    }

    public function destroy(Request $request, string $id, AccountEmailService $emails, RiderSettingsService $settings): JsonResponse
    {
        $this->inputs($request, ['current_password']);
        $emails->manage($request, $this->address($id), remove: true);

        return response()->json(['data' => $settings->snapshot($request)]);
    }
}
