<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Order;
use App\Rules\ApplicationText;
use App\Rules\BirthDate;
use App\Services\AccountSettingsService;
use App\Services\BirthDateEligibility;
use App\Services\BuyerAccessService;
use App\Services\Commerce\BuyerAddressService;
use App\Services\Commerce\PhilippineAddressCatalog;
use App\Services\IdentityCorrectionService;
use App\Services\Orders\CheckoutQuoteService;
use App\Services\Orders\OrderTrackingService;
use App\Services\Orders\OrderWorkspaceService;
use App\Services\ProfileInputService;
use App\Services\VerificationDocumentService;
use App\Services\Vouchers\VoucherCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BuyerProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = app(BuyerAccessService::class)->requireOrderHistory($request->user());
        $editingAddress = $request->route('address');
        if ($editingAddress instanceof Address) {
            app(BuyerAccessService::class)->requirePortal($user);
            abort_unless($editingAddress->user_id === $user->id, 403, 'This address is not available to your account.');
        }

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
        $counts = null;
        $countOrders = function () use (&$counts, $workspace, $owned) {
            return $counts ??= $workspace->counts(clone $owned, 'buyer');
        };
        $settings = null;
        $accountSettings = function () use (&$settings, $user) {
            return $settings ??= app(AccountSettingsService::class)->presentation($user);
        };
        $available = null;
        $offers = function () use (&$available, $user) {
            return $available ??= $user->canAccessPortal() ? app(CheckoutQuoteService::class)->offers(buyer: $user) : ['offers' => [], 'count' => 0];
        };

        $initialTab = $request->query('tab', 'orders');

        return Inertia::render('Buyer/Profile', [
            'identityCorrection' => fn () => $accountSettings()['identityCorrection'],
            'emailSettings' => fn () => $accountSettings()['emailSettings'],
            'user' => [...$user->toArray(), ...app(VerificationDocumentService::class)->links($user)],
            'addresses' => fn () => $user->addresses()->orderByDesc('is_default')->oldest()->get(),
            'editingAddressId' => $editingAddress instanceof Address ? $editingAddress->id : null,
            'addressBookLimit' => BuyerAddressService::LIMIT,
            'wallet' => $wallet,
            'orders' => function () use ($workspace, $owned, $status, $user) {
                $orders = $workspace->paginate($workspace->stable($workspace->filter(clone $owned, 'buyer', $status))
                    ->with(['items.product.shop', 'delivery.courier:id,name', ...OrderTrackingService::relations(history: false)]), 12);
                foreach ($orders as $order) {
                    $order->setAttribute('can_confirm_receipt', $workspace->canConfirmReceipt($order, $user));
                    $order->setAttribute('can_buy_again', $order->buyer_id === $user->id && $user->canAccessPortal() && $order->status === 'completed');
                    app(OrderTrackingService::class)->attach($order, history: false);
                }

                return $orders;
            },
            'ordersCount' => fn () => $countOrders()['all'],
            'orderCounts' => $countOrders,
            'currentOrderStatus' => $status,
            'initialTab' => $initialTab,
            'canUsePortal' => $user->canAccessPortal(),
            'availableVouchers' => fn () => $offers()['offers'],
            'availableVoucherCount' => fn () => $offers()['count'],
            'voucherCollection' => fn () => app(VoucherCollectionService::class)->collection($user, $request),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        return app(IdentityCorrectionService::class)->mutateProfile($request, function () use ($request) {
            $user = app(BuyerAccessService::class)->requireAccount($request->user());

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

    public function editAddress(Request $request, Address $address): Response
    {
        $request->query->set('tab', 'addresses');

        return $this->index($request);
    }

    public function addressLocations(Request $request): JsonResponse
    {
        app(BuyerAccessService::class)->requirePortal($request->user());
        $input = $request->validate(['region' => ['nullable', 'string', 'regex:/\A[0-9]{10}\z/'],
            'province' => ['nullable', 'string', new ApplicationText('location', 2, 100)],
            'city' => ['nullable', 'string', new ApplicationText('location', 2, 100)]]);

        return response()->json(app(PhilippineAddressCatalog::class)->options($input))->header('Cache-Control', 'no-store, private');
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
