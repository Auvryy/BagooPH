<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\AccountRecoveryNotification;
use App\Services\AccountClosureService;
use App\Services\BirthDateEligibility;
use App\Services\SecretMailService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasApiTokens, HasFactory, Notifiable;

    public const APPROVED_KYC_STATUSES = ['approved', 'verified'];

    protected $attributes = ['identity_version' => 0];

    protected $fillable = [
        'name',
        'email_verified_at',
        'first_name',
        'last_name',
        'middle_name',
        'sex',
        'birthday',
        'age',
        'email',
        'google_id',
        'password',
        'role',
        'phone',
        'avatar',
        'address',
        'city',
        'province',
        'municipality',
        'barangay',
        'postal_code',
        'status',
        'kyc_status',
        'id_document_path',
        'business_permit_path',
        'driver_license_path',
        'or_cr_path',
        'kyc_feedback',
        'kyc_submitted_at',
        'kyc_reviewed_at',
    ];

    protected $hidden = [
        'identity_version',
        'closed_at',
        'restriction_version',
        'password',
        'remember_token',
        'id_document_path',
        'business_permit_path',
        'driver_license_path',
        'or_cr_path',
    ];

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->age = app(BirthDateEligibility::class)->age($user->birthday);
        });
        static::updating(function (User $user): void {
            if ($user->isDirty('email')) {
                if (strcasecmp($user->email, $user->getRawOriginal('email')) !== 0) {
                    throw ValidationException::withMessages(['email' => 'Keep your original sign-in email. Add and verify another address in account settings.']);
                }
                $user->email = $user->getRawOriginal('email');
            }
            if ($user->getRawOriginal('closed_at') !== null && ($user->isDirty('closed_at') || $user->status !== 'inactive')) {
                throw ValidationException::withMessages(['status' => 'Closed accounts remain inactive and retained. Reopening requires a separate policy.']);
            }
            if ($user->isDirty('role')) {
                throw ValidationException::withMessages([
                    'role' => 'Account roles cannot be changed. Register a separate account for another role.',
                ]);
            }
            if ($user->isDirty(['birthday', 'age'])) {
                $user->age = app(BirthDateEligibility::class)->age($user->birthday);
            }
        });
        static::deleting(function (User $user): void {
            if (! app(AccountClosureService::class)->canHardDelete($user)) {
                throw new \LogicException('Referenced account identities must be retained. Use the account closure review.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'birthday' => 'date',
            'age' => 'integer',
            'kyc_submitted_at' => 'datetime',
            'kyc_reviewed_at' => 'datetime',
            'restriction_version' => 'integer',
            'identity_version' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function sendEmailVerificationNotification(): void
    {
        app(SecretMailService::class)->assertSafeTransport();
        parent::sendEmailVerificationNotification();
    }

    public function accountEmails(): HasMany
    {
        return $this->hasMany(AccountEmail::class);
    }

    public function routeNotificationForMail($notification): string
    {
        if ($notification instanceof AccountRecoveryNotification) {
            return $notification->recipient;
        }
        // Verification and broker recovery always refer to the original sign-in identity.
        if ($notification instanceof VerifyEmail || $notification instanceof ResetPassword) {
            return $this->email;
        }

        return $this->accountEmails()->whereKey($this->preferred_contact_email_id)->whereNotNull('verified_at')->value('email') ?? $this->email;
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        app(SecretMailService::class)->assertSafeTransport();
        parent::sendPasswordResetNotification($token);
    }

    public function isSeller(): bool
    {
        return $this->role === 'seller';
    }

    public function isBuyer(): bool
    {
        return $this->role === 'buyer';
    }

    public function isCourier(): bool
    {
        return $this->role === 'courier';
    }

    public function canDeleteOwnAccount(): bool
    {
        return app(AccountClosureService::class)->canSelfDelete($this);
    }

    public function isLogistics(): bool
    {
        return $this->role === 'logistics';
    }

    public function isKycApproved(): bool
    {
        return $this->isAdmin() || in_array($this->kyc_status, self::APPROVED_KYC_STATUSES, true);
    }

    public function isEligibleCourier(): bool
    {
        return $this->isCourier() && $this->canAccessPortal();
    }

    /**
     * Account eligibility only; each action must still authorize its resource scope.
     */
    public function canAccessPortal(): bool
    {
        return UserRole::tryFrom($this->role) !== null
            && $this->closed_at === null
            && $this->status === 'active'
            && $this->isKycApproved()
            && $this->hasEligibleBirthDate();
    }

    public function hasEligibleBirthDate(): bool
    {
        // Reviewed legacy accounts without a birth date retain access pending a controlled audit.
        $birthDates = app(BirthDateEligibility::class);
        if ($this->birthday === null) {
            return ! $birthDates->requiresAdult($this->role) || $this->identity_version === 0;
        }

        return $birthDates->issue($this->birthday, $birthDates->requiresAdult($this->role)) === null;
    }

    public function scopeEligibleCouriers(Builder $query): Builder
    {
        return $this->eligibleWorkerQuery($query, 'courier');
    }

    public function scopeEligibleLogisticsAccounts(Builder $query): Builder
    {
        return $this->eligibleWorkerQuery($query, 'logistics');
    }

    private function eligibleWorkerQuery(Builder $query, string $role): Builder
    {
        return $query->where('role', $role)->where('status', 'active')->whereNull('closed_at')
            ->whereIn('kyc_status', self::APPROVED_KYC_STATUSES)
            ->where(function (Builder $query) {
                $query->where(fn ($legacy) => $legacy->whereNull('birthday')->where('identity_version', 0))->orWhere(function (Builder $query) {
                    $query->whereDate('birthday', '>=', '0001-01-01')
                        ->whereDate('birthday', '<=', app(BirthDateEligibility::class)->limits()['adult_maximum']);
                });
            });
    }

    /**
     * Returns whether this account may submit a checkout order.
     *
     * KYC approval is intentionally evaluated from the reviewed status, not
     * from the presence of a path in the profile payload. A document can be
     * stored privately while the account remains approved.
     */
    public function canCompleteCheckout(): bool
    {
        return $this->isBuyer() && $this->canAccessPortal();
    }

    public function isKycPending(): bool
    {
        return $this->kyc_status === 'pending_approval';
    }

    public function isKycRejected(): bool
    {
        return $this->kyc_status === 'rejected';
    }

    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class);
    }

    public function courierProfile(): HasOne
    {
        return $this->hasOne(CourierProfile::class);
    }

    public function logisticsCompany(): HasOne
    {
        return $this->hasOne(LogisticsCompany::class);
    }

    public function hubHandlers(): HasMany
    {
        return $this->hasMany(HubHandler::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function courierDeliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'courier_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'buyer_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function defaultAddress(): ?Address
    {
        return $this->addresses()->where('is_default', true)->first()
            ?? $this->addresses()->oldest()->first();
    }
}
