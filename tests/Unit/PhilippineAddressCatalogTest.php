<?php

namespace Tests\Unit;

use App\Services\Commerce\PhilippineAddressCatalog;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhilippineAddressCatalogTest extends TestCase
{
    public function test_bundled_location_reference_has_complete_parent_links_and_unique_barangay_codes(): void
    {
        $index = json_decode(file_get_contents(resource_path('data/philippine-addresses/index.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(18, $index['regions']);
        $codes = [];
        foreach ($index['regions'] as $region => $label) {
            $cities = json_decode(file_get_contents(resource_path('data/philippine-addresses/barangays-'.$region.'.json')), true, flags: JSON_THROW_ON_ERROR);
            foreach ($cities as $city => $barangays) {
                $this->assertArrayHasKey($city, $index['cities']);
                $parent = $index['cities'][$city];
                $this->assertSame((string) $region, $parent['region']);
                $this->assertArrayHasKey($parent['province'], $index['provinces']);
                $this->assertSame((string) $region, $index['provinces'][$parent['province']]['region']);
                foreach ($barangays as $code => $name) {
                    $this->assertFalse(isset($codes[$code]), 'A barangay must have one parent in this reference.');
                    $codes[$code] = true;
                }
            }
        }
        $this->assertCount(42010, $codes);
    }

    public function test_manila_barangays_are_available_under_the_city_and_negros_uses_its_own_region(): void
    {
        $catalog = app(PhilippineAddressCatalog::class);
        $options = $catalog->options(['region' => '1300000000', 'province' => 'Metro Manila', 'city' => 'Manila']);
        $this->assertSame('City of Manila', $options['resolved']['city']);
        $this->assertGreaterThan(800, count($options['barangays']));
        $this->assertContains('Barangay 659', array_column($options['barangays'], 'value'));
        $this->assertSame('1800000000', $catalog->regionFor('Negros Oriental', 'Dumaguete City'));
    }

    public function test_old_city_spelling_and_case_resolve_without_accepting_an_unrelated_city(): void
    {
        $catalog = app(PhilippineAddressCatalog::class);
        $options = $catalog->options(['region' => '1300000000', 'province' => 'metro manila', 'city' => 'makati city']);
        $this->assertSame('City of Makati', $options['resolved']['city']);
        $this->expectException(ValidationException::class);
        $catalog->options(['region' => '1300000000', 'province' => 'Metro Manila', 'city' => 'City of Cebu']);
    }
}
