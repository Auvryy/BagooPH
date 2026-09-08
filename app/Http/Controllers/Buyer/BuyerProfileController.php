<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        // Migrate legacy profile address if user has no saved addresses
        if ($user->addresses()->count() === 0 && $user->address && $user->city) {
            $user->addresses()->create([
                'recipient_name' => $user->name,
                'phone' => $user->phone ?? '+63 912 345 6789',
                'province' => 'Metro Manila',
                'city' => $user->city,
                'barangay' => null,
                'street' => $user->address,
                'postal_code' => $user->postal_code,
                'type' => 'Home',
                'is_default' => true,
            ]);
        }

        $addresses = $user->addresses()->orderByDesc('is_default')->oldest()->get();

        $wallet = [
            'balance' => 5000.00,
            'currency' => 'PHP',
            'status' => 'Active',
            'account_number' => 'BG-WLT-' . str_pad((string)$user->id, 6, '0', STR_PAD_LEFT),
            'recent_transactions' => [
                ['id' => 'tx-1', 'title' => 'Top-up via Sandbox Simulation', 'amount' => 5000.00, 'type' => 'credit', 'date' => 'Today'],
                ['id' => 'tx-2', 'title' => 'Order Payment #ORD-8821', 'amount' => -1250.00, 'type' => 'debit', 'date' => 'Yesterday'],
            ],
        ];

        $orders = Order::where('buyer_id', $user->id)
            ->with(['items.product.shop', 'delivery.courier'])
            ->latest()
            ->get();

        $initialTab = $request->query('tab', 'orders');

        return Inertia::render('Buyer/Profile', [
            'user' => $user,
            'addresses' => $addresses,
            'wallet' => $wallet,
            'orders' => $orders,
            'ordersCount' => $orders->count(),
            'initialTab' => $initialTab,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $rules = [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'birthday' => 'nullable|date',
            'gender' => 'nullable|string|in:male,female,other',
            'remove_avatar' => 'nullable|boolean',
        ];

        if ($request->file('avatar') !== null) {
            $rules['avatar'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
        } elseif ($request->file('avatar_file') !== null) {
            $rules['avatar_file'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
        } else {
            $rules['avatar'] = 'nullable|string|max:1000';
            $rules['avatar_preset'] = 'nullable|string|max:1000';
        }

        $validated = $request->validate($rules);

        if ($request->boolean('remove_avatar')) {
            if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                $oldPath = str_replace('/storage/', '', $user->avatar);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $user->avatar = null;
        } else {
            $uploadedFile = $request->file('avatar') ?? $request->file('avatar_file');

            if ($uploadedFile) {
                // If replacing an existing custom avatar stored in public storage, delete the old file
                if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                    $oldPath = str_replace('/storage/', '', $user->avatar);
                    if (Storage::disk('public')->exists($oldPath)) {
                        Storage::disk('public')->delete($oldPath);
                    }
                }

                $path = $uploadedFile->store('avatars', 'public');
                $user->avatar = '/storage/' . $path;
            } elseif ($request->filled('avatar_preset') || $request->filled('avatar')) {
                $avatarPreset = trim((string) ($request->input('avatar_preset') ?? $request->input('avatar')));
                if ($avatarPreset !== '' && $avatarPreset !== $user->avatar) {
                    if ($user->avatar && str_starts_with($user->avatar, '/storage/')) {
                        $oldPath = str_replace('/storage/', '', $user->avatar);
                        if (Storage::disk('public')->exists($oldPath)) {
                            Storage::disk('public')->delete($oldPath);
                        }
                    }
                    $user->avatar = $avatarPreset;
                }
            }
        }

        $user->name = $validated['name'];
        $user->phone = $validated['phone'] ?? null;
        $user->save();

        return back()->with('success', 'Profile updated successfully.');
    }

    public function storeAddress(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'recipient_name' => 'nullable|string|max:255',
            'phone' => 'required|string|max:50',
            'province' => 'nullable|string|max:100',
            'city' => 'required|string|max:100',
            'barangay' => 'nullable|string|max:100',
            'street' => 'required|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'type' => 'nullable|string|max:50',
            'is_default' => 'nullable|boolean',
        ]);

        $hasExistingAddresses = $user->addresses()->exists();
        $isDefault = $request->boolean('is_default');

        // First address created automatically becomes default
        if (! $hasExistingAddresses || $isDefault) {
            $user->addresses()->update(['is_default' => false]);
            $isDefault = true;
        }

        $user->addresses()->create([
            'recipient_name' => $user->name, // Strictly locked to verified account name
            'phone' => $validated['phone'],
            'province' => $validated['province'] ?? null,
            'city' => $validated['city'],
            'barangay' => $validated['barangay'] ?? null,
            'street' => $validated['street'],
            'postal_code' => $validated['postal_code'] ?? null,
            'type' => $validated['type'] ?? 'Home',
            'is_default' => $isDefault,
        ]);

        return back()->with('success', 'Address added successfully.');
    }

    public function setDefaultAddress(Request $request, Address $address): RedirectResponse
    {
        $user = $request->user();

        if ($address->user_id !== $user->id) {
            abort(403);
        }

        $user->addresses()->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        return back()->with('success', 'Default address updated.');
    }

    public function destroyAddress(Request $request, Address $address): RedirectResponse
    {
        $user = $request->user();

        if ($address->user_id !== $user->id) {
            abort(403);
        }

        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            $oldest = $user->addresses()->oldest()->first();
            if ($oldest) {
                $oldest->update(['is_default' => true]);
            }
        }

        return back()->with('success', 'Address deleted successfully.');
    }
}
