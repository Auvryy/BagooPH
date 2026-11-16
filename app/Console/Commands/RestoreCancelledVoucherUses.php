<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\VoucherOrderAllocation;
use App\Services\Vouchers\VoucherFundingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Gives back voucher uses held by orders cancelled before cancellations restored them automatically. */
class RestoreCancelledVoucherUses extends Command
{
    protected $signature = 'vouchers:restore-cancelled-uses';

    protected $description = 'Return voucher uses that fully cancelled orders still hold';

    public function handle(VoucherFundingService $funding): int
    {
        $restored = 0;
        Order::where('status', 'cancelled')->whereIn('id', VoucherOrderAllocation::select('order_id'))->orderBy('id')
            ->chunkById(100, function ($orders) use ($funding, &$restored) {
                foreach ($orders as $order) {
                    $restored += DB::transaction(fn () => $funding->restoreCancelledUses($order));
                }
            });
        $this->info("Restored {$restored} voucher ".($restored === 1 ? 'use' : 'uses').'.');

        return self::SUCCESS;
    }
}
