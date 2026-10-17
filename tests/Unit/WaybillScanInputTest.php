<?php

namespace Tests\Unit;

use App\Services\Logistics\WaybillScanInputService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class WaybillScanInputTest extends TestCase
{
    public function test_only_known_platform_tracking_urls_can_be_used_as_legacy_web_scans(): void
    {
        config(['app.url' => 'https://bagoo.test']);
        $input = app(WaybillScanInputService::class);
        foreach (['https://bagoo.test/track/bgo-one', 'https://seller.bagoo.test/track/BGO-ONE/', ' BGO-ONE '] as $value) {
            $normalized = $input->normalize(['barcode' => $value], allowTrackingUrl: true);
            $this->assertSame('BGO-ONE', $normalized['barcode']);
            $this->assertFalse(Validator::make($normalized, ['barcode' => $input->barcodeRules()])->fails());
        }
        foreach (['https://foreign.test/track/BGO-ONE', 'https://bagoo.test.foreign.test/track/BGO-ONE', 'https://user@bagoo.test/track/BGO-ONE',
            'https://bagoo.test/track/BGO-ONE?next=/hub', 'https://bagoo.test/track/BGO-ONE#extra', 'https://bagoo.test/track/BGO%2FONE',
            'https://bagoo.test:8443/track/BGO-ONE', 'javascript:BGO-ONE', 'BGO-ONE BGO-TWO', "\tBGO-ONE", 'ＢＧＯ-ONE', str_repeat('A', 256)] as $value) {
            $this->assertTrue(Validator::make($input->normalize(['barcode' => $value], allowTrackingUrl: true), ['barcode' => $input->barcodeRules()])->fails(), $value);
        }
        $this->assertTrue(Validator::make($input->normalize(['barcode' => 'https://bagoo.test/track/BGO-ONE']), ['barcode' => $input->barcodeRules()])->fails(), 'Other API/service contracts retain the raw-code boundary.');
    }
}
