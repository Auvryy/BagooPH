<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class BuyerAccessService
{
    public function current(User $actor, bool $lock = false): User
    {
        $query = User::whereKey($actor->id);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $user) {
            throw new AuthorizationException('This account is no longer available.');
        }

        return $user;
    }

    public function canViewHolding(User $user): bool
    {
        return $user->isBuyer()
            && $user->closed_at === null
            && in_array($user->status, ['active', 'pending_approval', 'inactive', 'suspended'], true)
            && in_array($user->kyc_status, ['none', 'pending_approval', 'rejected', ...User::APPROVED_KYC_STATUSES], true);
    }

    public function canManageApplication(User $user): bool
    {
        return $this->canViewHolding($user) && in_array($user->kyc_status, ['none', 'pending_approval', 'rejected'], true);
    }

    public function requireApplication(User $user): void
    {
        abort_unless($this->canViewHolding($user), 403, 'Your account cannot use this application path.');
        abort_unless($this->canManageApplication($user), 409, 'A completed review requires a controlled identity correction.');
    }

    public function requirePortal(User $actor, bool $lock = false): User
    {
        $user = $this->current($actor, $lock);
        if (! $user->isBuyer() || ! $user->canAccessPortal()) {
            throw new AuthorizationException('An active approved buyer account is required.');
        }

        return $user;
    }

    public function requireAccount(User $actor, bool $lock = false): User
    {
        $user = $this->current($actor, $lock);
        if (! $this->canViewHolding($user)) {
            throw new AuthorizationException('This buyer account is not available.');
        }

        return $user;
    }

    public function requireBagChanges(User $actor, bool $lock = false): User
    {
        $user = $this->requireAccount($actor, $lock);
        if (! in_array($user->status, ['active', 'pending_approval'], true)) {
            throw new AuthorizationException('Your account cannot change its Shopping Bag while restricted.');
        }

        return $user;
    }

    public function canViewOrderHistory(User $user): bool
    {
        return $this->canViewHolding($user)
            && Gate::forUser($user)->allows('buyer.order-history')
            && (! $user->isKycApproved() || $this->canAccessExistingOrders($user));
    }

    public function requireOrderHistory(User $actor): User
    {
        $user = $this->requireAccount($actor);
        abort_unless($this->canViewOrderHistory($user), 403);

        return $user;
    }

    public function applicationPresentation(?User $user): ?array
    {
        if (! $user || ! $this->canViewHolding($user)) {
            return null;
        }
        $canCorrect = $this->canManageApplication($user);

        return [
            'user' => $user->only(['id', 'name', 'status', 'kyc_status', 'kyc_feedback', 'kyc_reviewed_at']),
            'can_use_portal' => $user->canAccessPortal(),
            'application' => $canCorrect ? app(ApplicationValidationService::class)->form($user) : null,
            'identity_document_url' => $canCorrect ? app(VerificationDocumentService::class)->links($user)['id_document_path'] : null,
            'birth_date_limits' => app(BirthDateEligibility::class)->limits(),
        ];
    }

    public function hasExistingOrderEligibility(User $user): bool
    {
        return $user->isBuyer() && $user->isKycApproved()
            && $user->closed_at === null
            && in_array($user->status, ['active', 'inactive', 'suspended'], true);
    }

    public function canAccessExistingOrders(User $user): bool
    {
        // A security authorization denial also applies to the restricted-account exception.
        return $this->hasExistingOrderEligibility($user)
            && Gate::forUser($user)->allows('buyer.existing-orders');
    }

    public function requireExistingOrders(User $actor, ?Order $order = null, bool $lock = false): User
    {
        $user = $this->current($actor, $lock);
        if (! $this->canAccessExistingOrders($user) || ($order && $order->buyer_id !== $user->id)) {
            throw new AuthorizationException('This existing order is not available to your account.');
        }

        return $user;
    }

    public function portalIssue(User $user): string
    {
        if ($user->status !== 'active') {
            return 'Your account is not active and cannot place an order.';
        }

        return match ($user->kyc_status) {
            'pending_approval' => 'Your ID verification is currently pending review. Please wait for approval before completing your purchase.',
            'rejected' => 'Your submitted ID was rejected. Please upload a corrected ID for review.',
            default => 'Identity verification is required before placing an order. Submit your application for review.',
        };
    }

    public function signInDestination(Request $request): RedirectResponse
    {
        $user = $this->current($request->user());
        if ($user->isBuyer() && $user->canAccessPortal()) {
            return redirect()->intended(route('buyer.index', absolute: false));
        }

        $request->session()->forget('url.intended');
        if ($this->canAccessExistingOrders($user)) {
            return redirect()->route('buyer.orders.index');
        }
        if ($this->canViewHolding($user)) {
            return redirect()->route('buyer.index', ['application' => 1]);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => 'Your account is not eligible to sign in. Please contact support.']);
    }
}
