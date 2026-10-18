<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'shop_id',
        'product_id',
        'order_id',
        'message',
        'is_read',
        'attachment_path', 'attachment_mime', 'attachment_size', 'attachment_hash',
        'request_key',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    protected $hidden = ['attachment_path', 'attachment_hash', 'request_key'];

    protected $appends = ['attachment_url'];

    public function getAttachmentUrlAttribute(): ?string
    {
        return $this->attachment_path ? route('chat.attachment', $this->id, absolute: false) : null;
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
