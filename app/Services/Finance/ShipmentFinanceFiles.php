<?php

namespace App\Services\Finance;

use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Services\Returns\BuyerReturnMoneyFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShipmentFinanceFiles
{
    public function inspect(UploadedFile $file): array
    {
        return app(BuyerReturnMoneyFiles::class)->inspect($file);
    }

    public function store(UploadedFile $file, array $info): string
    {
        $path = $file->storeAs('shipment-finance', Str::uuid().'.'.$info['extension'], 'local');
        abort_unless($path && Storage::disk('local')->exists($path), 503, 'The financial evidence could not be saved.');

        return $path;
    }

    public function discardUnused(array $paths): void
    {
        foreach ($paths as $path) {
            if (! ShipmentFinancialPlan::where('proof_path', $path)->exists() && ! ShipmentFinancialEvent::where('proof_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
