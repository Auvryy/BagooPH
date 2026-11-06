<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoreTag extends Model
{
    protected $fillable = ['shop_id', 'name', 'normalized_name'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_store_tag')->withTimestamps();
    }
}
