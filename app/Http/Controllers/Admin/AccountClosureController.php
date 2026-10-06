<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AccountClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AccountClosureController extends Controller
{
    public function show(Request $request, int $user, AccountClosureService $closures): Response
    {
        return Inertia::render('Admin/AccountClosure', ['closure' => $closures->presentation($request->user(), $user),
            'endpoint' => $request->is('admin/*') ? '/admin/users/'.$user.'/closure' : '/users/'.$user.'/closure',
            'usersUrl' => $request->is('admin/*') ? '/admin/users' : '/users']);
    }

    public function store(Request $request, int $user, AccountClosureService $closures): JsonResponse
    {
        $closure = $closures->close($request->user(), $user, $request->all());
        $self = $request->user()->id === $user;
        if ($self) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['id' => $closure->id, 'outcome' => $closure->outcome, 'signed_out' => $self]);
    }
}
