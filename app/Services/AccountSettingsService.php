<?php

namespace App\Services;

use App\Models\User;

class AccountSettingsService
{
    public function presentation(User $user): array
    {
        $correction = null;
        if ($user->closed_at === null && $user->isKycApproved() && (! $user->isSeller() || $user->shop()->exists())) {
            $correction = app(IdentityCorrectionService::class)->form($user, $user);
        }

        return ['identityCorrection' => $correction ? ['subject' => $correction, 'categories' => app(MasterCategoryService::class)->choices()] : null,
            'emailSettings' => app(AccountEmailService::class)->presentation($user)];
    }
}
