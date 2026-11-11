<?php

namespace Tests\Feature\Logistics;

use App\Exceptions\CheckoutException;
use App\Models\CartItem;
use App\Models\LogisticsCompany;
use App\Models\Product;
use App\Models\User;
use App\Services\Logistics\LogisticsRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WeightBasedTariffTest extends TestCase
{
    use RefreshDatabase;

    public static function weights(): array
    {
        return ['below allowance' => ['0.50', 5000], 'exact allowance' => ['1.00', 5000],
            'first overage' => ['1.01', 7000], 'two kilograms' => ['2.00', 7000],
            'second overage' => ['2.01', 9000], 'three kilograms' => ['3.00', 9000]];
    }

    #[DataProvider('weights')]
    public function test_default_tariff_charges_fifty_plus_twenty_per_started_extra_kilogram(string $weight, int $expected): void
    {
        $company = $this->company();
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', new Product(['weight_kg' => $weight, 'length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00']));
        $quote = app(LogisticsRateService::class)->quote(collect([$item]), $company);
        $this->assertSame(5000, $company->base_shipping_cents);
        $this->assertSame(2000, $company->additional_kg_cents);
        $this->assertSame($expected, $quote['shipping_cents']);
        $this->assertSame($expected, array_sum(app(LogisticsRateService::class)->shippingSplit($expected, 'dedicated_fleet')));
    }

    public function test_bulky_lightweight_units_use_volumetric_weight_and_quantities(): void
    {
        $item = new CartItem(['quantity' => 2]);
        $item->setRelation('product', new Product(['weight_kg' => '0.50', 'length_cm' => '35.00', 'width_cm' => '20.00', 'height_cm' => '10.00']));
        $quote = app(LogisticsRateService::class)->quote(collect([$item]), $this->company());
        $this->assertSame(1000, $quote['actual_weight_grams']);
        $this->assertSame(4000, $quote['volumetric_weight_grams']);
        $this->assertSame(11000, $quote['shipping_cents']);
    }

    public function test_custom_tariffs_are_not_replaced_by_the_new_default_or_migration(): void
    {
        $company = $this->company(['base_shipping_cents' => 6000, 'additional_kg_cents' => 0, 'tariff_version' => 4]);
        $before = $company->getAttributes();
        $migration = require database_path('migrations/2026_11_11_010000_update_future_company_shipping_defaults.php');
        $migration->up();
        $this->assertSame($before, $company->fresh()->getAttributes());
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', new Product(['weight_kg' => '10.00', 'length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00']));
        $this->assertSame(6000, app(LogisticsRateService::class)->quote(collect([$item]), $company)['shipping_cents']);
    }

    public function test_selected_weight_tariff_cannot_undercharge_unknown_packed_measurements(): void
    {
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', new Product(['weight_kg' => '0.50', 'length_cm' => null, 'width_cm' => '10.00', 'height_cm' => '10.00']));
        $this->expectException(CheckoutException::class);
        app(LogisticsRateService::class)->quote(collect([$item]), $this->company());
    }

    public static function invalidTariffs(): array
    {
        return ['negative base' => [['base_shipping_cents' => -1]], 'negative overage' => [['additional_kg_cents' => -1]],
            'no included weight' => [['included_weight_grams' => 0]], 'unknown model' => [['shipping_earnings_model' => 'unknown']]];
    }

    #[DataProvider('invalidTariffs')]
    public function test_inconsistent_company_tariffs_return_a_checkout_error_instead_of_negative_money(array $tariff): void
    {
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', new Product(['weight_kg' => '0.50', 'length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00']));
        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage('shipping tariff needs review');
        app(LogisticsRateService::class)->quote(collect([$item]), $this->company($tariff));
    }

    public function test_negative_actual_weight_cannot_be_treated_as_a_valid_weight_quote(): void
    {
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', new Product(['weight_kg' => '-1.00', 'length_cm' => '10.00', 'width_cm' => '10.00', 'height_cm' => '10.00']));
        $this->expectException(CheckoutException::class);
        app(LogisticsRateService::class)->quote(collect([$item]), $this->company());
    }

    private function company(array $tariff = []): LogisticsCompany
    {
        return LogisticsCompany::create($tariff + ['user_id' => User::factory()->create(['role' => 'logistics'])->id,
            'name' => 'Bagoo Tariff Test Company', 'slug' => 'bagoo-tariff-test', 'code' => 'TARIFF-TEST',
            'status' => 'active', 'is_active' => true])->fresh();
    }
}
