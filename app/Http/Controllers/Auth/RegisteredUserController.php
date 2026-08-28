<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Display the dedicated seller registration view.
     */
    public function createSeller(): Response
    {
        return Inertia::render('Auth/SellerRegister');
    }

    /**
     * Display the dedicated courier registration view.
     */
    public function createCourier(): Response
    {
        return Inertia::render('Auth/CourierRegister');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'role' => 'nullable|string|in:buyer,seller,courier',
            'shop_name' => 'nullable|string|max:255',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $role = $request->input('role', 'buyer');

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => $role,
            'password' => Hash::make($request->password),
        ]);

        if ($role === 'seller') {
            $shopName = $request->input('shop_name') ?: $user->name . "'s Store";
            \App\Models\Shop::create([
                'user_id' => $user->id,
                'name' => $shopName,
                'slug' => \Illuminate\Support\Str::slug($shopName . '-' . $user->id),
                'status' => 'active',
            ]);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect()->intended(match($user->role) {
            'admin' => route('admin.dashboard', absolute: false),
            'seller' => route('seller.dashboard', absolute: false),
            'courier' => route('courier.deliveries', absolute: false),
            default => route('buyer.index', absolute: false),
        });
    }
}
