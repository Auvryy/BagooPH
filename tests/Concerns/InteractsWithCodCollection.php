<?php

namespace Tests\Concerns;

use App\Models\Delivery;
use Illuminate\Support\Str;

trait InteractsWithCodCollection
{
    private array $codCollectionRequests = [];

    protected function codCollectionInput(Delivery $delivery, array $overrides = []): array
    {
        $this->codCollectionRequests[$delivery->id] ??= [
            'barcode' => $delivery->tracking_number, 'recipient_name' => $delivery->order->recipient_name ?? $delivery->order->buyer->name,
            'recipient_relationship' => 'buyer', 'cash_received' => (string) $delivery->order->total_amount,
            'change_given' => '0.00', 'cash_confirmed' => true, 'request_token' => (string) Str::uuid(),
        ];

        return array_replace($this->codCollectionRequests[$delivery->id], $overrides);
    }
}
