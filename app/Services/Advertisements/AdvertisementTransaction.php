<?php

namespace App\Services\Advertisements;

use App\Models\Advertisement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdvertisementTransaction
{
    public function run(User $actor, callable $authorize, callable $work): Advertisement
    {
        abort_unless(app(AdvertisementManager::class)->installed(), 503, 'Install the pending advertisement migration before managing promotions.');
        $paths = [];
        try {
            $result = DB::transaction(function () use ($actor, $authorize, $work, &$paths) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $authorize($actor);
                // Admin placements and seller/reviewer changes share this lock order.
                abort_unless(DB::table('advertisement_schedule_locks')->where('id', 1)->lockForUpdate()->first(), 503, 'The advertisement calendar needs to be installed.');

                return $work($actor, $paths);
            }, 3);
            app(AdvertisementAssetService::class)->cleanUnreferenced($paths);

            return $result;
        } catch (Throwable $exception) {
            app(AdvertisementAssetService::class)->cleanUnreferenced($paths);
            throw $exception;
        }
    }
}
