<?php

namespace App\Services\Commerce;

use App\Models\Address;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuyerAddressService
{
    public const LIMIT = 10;

    public function create(User $actor, array $input): Address
    {
        app(BuyerAccessService::class)->requirePortal($actor);

        return DB::transaction(function () use ($actor, $input) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            $data = app(CommerceInputService::class)->address($input);
            $addresses = $buyer->addresses()->orderBy('id')->lockForUpdate()->get();
            if ($addresses->count() >= self::LIMIT) {
                throw ValidationException::withMessages(['address_book' => 'You can save up to 10 addresses. Remove a non-default address before adding another.']);
            }
            $data['is_default'] = $addresses->isEmpty() || $data['is_default'];
            if ($data['is_default']) {
                foreach ($addresses->where('is_default', true) as $address) {
                    $address->update(['is_default' => false]);
                }
            }

            return $buyer->addresses()->create($data);
        });
    }

    public function setDefault(User $actor, Address $address): void
    {
        $this->mutate($actor, $address, function ($addresses, Address $owned) {
            foreach ($addresses->where('is_default', true)->except($owned->id) as $previous) {
                $previous->update(['is_default' => false]);
            }
            if (! $owned->is_default) {
                $owned->update(['is_default' => true]);
            }
        });
    }

    public function update(User $actor, Address $address, array $input): void
    {
        $this->mutate($actor, $address, function ($addresses, Address $owned) use ($input) {
            $data = app(CommerceInputService::class)->address($input);
            // Keeping the current default avoids leaving the address book without one.
            $data['is_default'] = $owned->is_default || $data['is_default'];
            if ($data['is_default']) {
                foreach ($addresses->where('is_default', true)->except($owned->id) as $previous) {
                    $previous->update(['is_default' => false]);
                }
            }
            $owned->update($data);
        });
    }

    public function delete(User $actor, Address $address): void
    {
        $this->mutate($actor, $address, function ($addresses, Address $owned) {
            if ($owned->is_default) {
                throw ValidationException::withMessages(['address_book' => 'Choose another address as default before deleting this one.']);
            }
            $owned->delete();
        });
    }

    private function mutate(User $actor, Address $address, callable $work): void
    {
        app(BuyerAccessService::class)->requirePortal($actor);
        DB::transaction(function () use ($actor, $address, $work) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            $addresses = $buyer->addresses()->orderBy('id')->lockForUpdate()->get();
            $owned = $addresses->find($address->id);
            abort_unless($owned, 403, 'This address is not available to your account.');
            $work($addresses, $owned);
        });
    }
}
