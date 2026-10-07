<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class LogisticsSortingInputTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function invalidInputs(): array
    {
        $values = [
            'bin Greek' => ['bin', 'BIN-α'],
            'bin lookalike' => ['bin', 'ＢＩＮ-A1'],
            'bin unsafe scheme' => ['bin', 'javascript:foo'],
            'bin newline' => ['bin', "BIN-A1\n"],
            'bin tab' => ['bin', "\tBIN-A1"],
            'bin hidden' => ['bin', "BIN-\u{200B}A1"],
            'bin markup' => ['bin', '<b>BIN-A1</b>'],
            'bin punctuation' => ['bin', '---'],
            'bin length' => ['bin', str_repeat('A', 151)],
            'barangay newline' => ['barangay', "Poblacion III\n"],
            'barangay tab' => ['barangay', "\tPoblacion III"],
            'barangay hidden' => ['barangay', "Poblacion\u{200B} III"],
            'barangay markup' => ['barangay', '<b>Poblacion III</b>'],
            'barangay length' => ['barangay', str_repeat('A', 101)],
            'notes control' => ['notes', "Check\x00bin"],
            'notes markup' => ['notes', '<script>check()</script>'],
            'notes length' => ['notes', str_repeat('A', 1001)],
        ];
        $cases = [];
        foreach (['root' => '/hub/sort', 'subdomain' => 'http://hub.localhost/sort'] as $portal => $url) {
            foreach ($values as $name => [$field, $value]) {
                $cases[$portal.' '.$name] = [$url, $field, $value];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_text_preserves_order_parcel_and_history(string $url, string $field, mixed $value): void
    {
        $delivery = $this->parcelAtDestination();
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($delivery->destinationBayanHub))
            ->postJson($url, ['delivery_id' => $delivery->id, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public static function validInputs(): array
    {
        return [
            'default' => ['/hub/sort', [], 'BIN: BRGY-POBLACION-III'],
            'subdomain default' => ['http://hub.localhost/sort', [], 'BIN: BRGY-POBLACION-III'],
            'blank optional' => ['/hub/sort', ['bin' => '  ', 'barangay' => '  '], 'BIN: BRGY-POBLACION-III'],
            'normalized text' => ['/hub/sort', ['bin' => ' BIN-A1 ', 'barangay' => ' poblacion   iii '], 'BIN-A1'],
            'boundary' => ['/hub/sort', ['bin' => str_repeat('A', 150), 'notes' => str_repeat('A', 1000)], str_repeat('A', 150)],
            'multiline notes' => ['/hub/sort', ['bin' => 'BIN-A1', 'notes' => "Checked shelf.\nMatched waybill."], 'BIN-A1'],
            'ASCII separator' => ['/hub/sort', ['bin' => 'SHELF_A.01'], 'SHELF_A.01'],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_valid_sorting_uses_recorded_destination_and_one_checkpoint(string $url, array $input, string $bin): void
    {
        $delivery = $this->parcelAtDestination();
        $order = $delivery->order->getRawOriginal();
        $this->actingAs($this->flowHandler($delivery->destinationBayanHub))
            ->postJson($url, ['delivery_id' => $delivery->id, ...$input])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
        $this->assertSame($bin, $delivery->fresh()->destination_bin);
        $this->assertSame($order['destination_barangay'], $delivery->order->fresh()->destination_barangay);
        $this->assertSame('sorted', $delivery->order->fresh()->status);
        $checkpoint = $delivery->checkpoints()->where('checkpoint_type', 'sorted_to_barangay_bin')->sole();
        $this->assertStringContainsString('Poblacion III', $checkpoint->location_name);
        if (isset($input['notes'])) {
            $this->assertSame($input['notes'], $checkpoint->notes);
        }
        $before = $this->snapshot($delivery);
        $this->postJson($url, ['delivery_id' => $delivery->id, 'bin' => 'BIN-A2'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_another_barangay_cannot_replace_the_recorded_destination(): void
    {
        $delivery = $this->parcelAtDestination();
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($delivery->destinationBayanHub))
            ->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'barangay' => 'San Antonio'])
            ->assertUnprocessable()->assertJsonValidationErrors('barangay');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_foreign_facility_and_wrong_role_cannot_sort(): void
    {
        $delivery = $this->parcelAtDestination();
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler(LogisticsHub::findOrFail($delivery->origin_bayan_hub_id)))
            ->postJson(route('hub.sort'), ['delivery_id' => $delivery->id])->assertForbidden();
        $this->actingAs($delivery->order->buyer)
            ->postJson(route('hub.sort'), ['delivery_id' => $delivery->id])->assertForbidden();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_invalid_legacy_destination_does_not_generate_a_general_bin(): void
    {
        $delivery = $this->parcelAtDestination();
        $delivery->order->update(['destination_barangay' => null]);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($delivery->destinationBayanHub))
            ->postJson(route('hub.sort'), ['delivery_id' => $delivery->id])
            ->assertUnprocessable()->assertJsonValidationErrors('barangay');
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_a_checkpoint_write_failure_rolls_back_sorting(): void
    {
        $delivery = $this->parcelAtDestination();
        $before = $this->snapshot($delivery);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, fn () => throw new RuntimeException('Sorting evidence unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->flowHandler($delivery->destinationBayanHub))
                ->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'bin' => 'BIN-A1']);
            $this->fail('A failed checkpoint write must not report success.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Sorting evidence unavailable', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    private function parcelAtDestination(): Delivery
    {
        return $this->flowDelivery($this->newFlowOrder(), 'arrived_at_destination_hub')->load('order', 'destinationBayanHub');
    }

    private function snapshot(Delivery $delivery): array
    {
        return [$delivery->order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->get()->toArray()];
    }
}
