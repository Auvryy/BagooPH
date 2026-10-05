<?php

namespace App\Models\Concerns;

use App\Services\ResourceRestrictionService;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait RetainsRestrictionHistory
{
    public function initializeRetainsRestrictionHistory(): void
    {
        $this->mergeCasts(['restriction_version' => 'integer']);
        $this->mergeHidden(['restriction_version']);
    }

    public static function bootRetainsRestrictionHistory(): void
    {
        static::deleting(function (Model $resource): void {
            if (app(ResourceRestrictionService::class)->hasHistory($resource)) {
                throw new LogicException('Resources referenced by restriction history must be retained.');
            }
        });
    }
}
