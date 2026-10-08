<?php

namespace App\Http\Controllers;

use App\Models\AccountEmail;
use App\Services\AccountEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountEmailController extends Controller
{
    public function send(Request $request, AccountEmailService $emails): JsonResponse
    {
        $result = $emails->send($request);
        $status = $result['success'] ? 200 : ($result['status'] ?? 429);
        unset($result['status']);

        return response()->json($result, $status);
    }

    public function confirm(Request $request, AccountEmailService $emails): JsonResponse
    {
        $emails->confirm($request);

        return response()->json(['message' => 'Email verified and added. Your sign-in email stays the same.']);
    }

    public function prefer(Request $request, AccountEmail $accountEmail, AccountEmailService $emails): JsonResponse
    {
        $emails->manage($request, $accountEmail, remove: false);

        return response()->json(['message' => 'Contact email updated.']);
    }

    public function destroy(Request $request, AccountEmail $accountEmail, AccountEmailService $emails): JsonResponse
    {
        $emails->manage($request, $accountEmail, remove: true);

        return response()->json(['message' => 'Additional email removed.']);
    }
}
