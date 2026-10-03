<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\VerificationDocumentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationDocumentController extends Controller
{
    public function show(Request $request, User $user, string $document, VerificationDocumentService $documents): StreamedResponse
    {
        return $documents->show($request->user(), $user, $document);
    }
}
