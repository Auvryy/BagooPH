<?php

namespace App\Services\Commerce;

use App\Models\Address;
use App\Models\User;
use App\Services\BuyerAccessService;
use Illuminate\Support\Facades\DB;

class BuyerAddressService
{
    public function create(User $actor, array $input): Address
    {
        app(BuyerAccessService::class)->requirePortal($actor);

        return DB::transaction(function () use ($actor, $input) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor, lock: true);
            $input['recipient_name'] = $buyer->name;
            $data = app(CommerceInputService::class)->address($input);
            $addresses = $buyer->addresses()->orderBy('id')->lockForUpdate()->get();
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
        $this->mutate($actor, $address, function ($addresses, Address $owned) use ($actor, $input) {
            $buyer = app(BuyerAccessService::class)->requirePortal($actor);
            $input['recipient_name'] = $buyer->name;
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
            $wasDefault = $owned->is_default;
            $owned->delete();
            if ($wasDefault) {
                $replacement = $addresses->except($owned->id)->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
                $replacement?->update(['is_default' => true]);
            }
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
