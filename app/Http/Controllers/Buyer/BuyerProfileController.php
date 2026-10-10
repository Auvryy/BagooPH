<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Rules\BirthDate;
use App\Services\AccountSettingsService;
use App\Services\BirthDateEligibility;
use App\Services\BuyerAccessService;
use App\Services\Commerce\BuyerAddressService;
use App\Services\IdentityCorrectionService;
use App\Services\Orders\OrderWorkspaceService;
use App\Services\ProfileInputService;
use App\Services\VerificationDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());

        $addresses = $user->addresses()->orderByDesc('is_default')->oldest()->get();

        $wallet = [
            'available' => false,
            'balance' => null,
            'currency' => 'PHP',
            'account_number' => null,
            'recent_transactions' => [],
        ];

        $workspace = app(OrderWorkspaceService::class);
        $status = $workspace->selection($request, 'buyer', 'order_status');
        $owned = Order::where('buyer_id', $user->id);
        $counts = $workspace->counts($owned, 'buyer');
        $orders = $workspace->paginate($workspace->stable($workspace->filter(clone $owned, 'buyer', $status))
            ->with(['items.product.shop', 'delivery.courier:id,name']), 12);
        foreach ($orders as $order) {
            $order->setAttribute('can_confirm_receipt', $workspace->canConfirmReceipt($order, $user));
            $order->setAttribute('can_buy_again', $order->buyer_id === $user->id && $user->canAccessPortal() && $order->status === 'completed');
        }

        $initialTab = $request->query('tab', 'orders');

        return Inertia::render('Buyer/Profile', [
            ...app(AccountSettingsService::class)->presentation($user),
            'user' => [...$user->toArray(), ...app(VerificationDocumentService::class)->links($user)],
            'addresses' => $addresses,
            'wallet' => $wallet,
            'orders' => $orders,
            'ordersCount' => $counts['all'],
            'orderCounts' => $counts,
            'currentOrderStatus' => $status,
            'initialTab' => $initialTab,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        return app(IdentityCorrectionService::class)->mutateProfile($request, function () use ($request) {
            $user = app(BuyerAccessService::class)->requirePortal($request->user());

            $rules = [
                'birthday' => ['nullable', new BirthDate(app(BirthDateEligibility::class)->requiresAdult($user->role))],
                'gender' => 'nullable|string|in:male,female,other',
                'remove_avatar' => 'nullable|boolean',
            ];

            if ($request->file('avatar') !== null) {
                $rules['avatar'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
            } elseif ($request->file('avatar_file') !== null) {
                $rules['avatar_file'] = 'required|image|mimes:jpeg,png,jpg,webp,gif|max:3072';
            }

            $validated = app(ProfileInputService::class)->validate($request, ['name', 'phone'], $rules);

            app(IdentityCorrectionService::class)->protectReviewedIdentity($user, $validated);

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
                    $user->avatar = '/storage/'.$path;
                }
            }

            $user->name = $validated['name'];
            $user->phone = $validated['phone'] ?? null;
            $user->save();

            return back()->with('success', 'Profile updated successfully.');
        });
    }

    public function storeAddress(Request $request): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());

        app(BuyerAddressService::class)->create($user, $request->all());

        return back()->with('success', 'Address added successfully.');
    }

    public function setDefaultAddress(Request $request, Address $address): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());

        app(BuyerAddressService::class)->setDefault($user, $address);

        return back()->with('success', 'Default address updated.');
    }

    public function updateAddress(Request $request, Address $address): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());
        app(BuyerAddressService::class)->update($user, $address, $request->all());

        return back()->with('success', 'Address updated for future orders. Existing orders keep their delivery details.');
    }

    public function destroyAddress(Request $request, Address $address): RedirectResponse
    {
        $user = app(BuyerAccessService::class)->requirePortal($request->user());

        app(BuyerAddressService::class)->delete($user, $address);

        return back()->with('success', 'Address deleted successfully.');
    }
}
