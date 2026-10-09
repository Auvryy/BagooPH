<?php

namespace Tests\Feature\Courier;

use App\Models\CodAccount;
use App\Models\User;
use App\Services\Courier\RiderApiResponse;
use App\Services\Courier\RiderTaskService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderNativeContractTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function native(User $rider): void
    {
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Contract test')['token']);
    }

    private function key(): string
    {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key);

        return $key;
    }

    private function capture(string $schema, TestResponse $response): TestResponse
    {
        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertTrue(Str::isUuid($response->json('request_id')));
        $this->assertSame($response->json('request_id'), $response->headers->get('X-Request-ID'));
        foreach (['proof_image', 'proof_path', 'private_evidence', 'credential_fingerprint'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'":', $response->getContent());
        }
        if (getenv('RIDER_CONTRACT_ARTIFACTS') === '1') {
            $directory = base_path('.codex/native-api/contract-samples');
            if (! is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($directory.'/'.$schema.'.json', json_encode($response->json(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return $response;
    }

    public function test_executable_contract_covers_every_implemented_operational_route(): void
    {
        $spec = json_decode(file_get_contents(base_path('docs/api/rider-operations.openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $implemented = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/rider/')
                || ! RiderApiResponse::applies(Request::create('/'.preg_replace('/\{[^}]+\}/', '12', $route->uri())))) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $implemented[] = $method.' '.substr($route->uri(), strlen('api/v1'));
            }
        }
        $documented = [];
        foreach ($spec['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                    $this->assertNotEmpty($operation['operationId']);
                    $this->assertArrayHasKey('200', $operation['responses']);
                }
            }
        }
        sort($implemented);
        sort($documented);
        $this->assertSame($implemented, $documented);
        $this->assertSame('3.1.0', $spec['openapi']);
    }

    public function test_native_home_claim_and_task_payloads_have_private_typed_contract_samples(): void
    {
        $parcel = $this->newFlowOrder('ready_for_pickup')->delivery;
        $rider = $this->flowRider($parcel->originBayanHub);
        $this->native($rider);
        $this->capture('HomeEnvelope', $this->getJson('/api/v1/rider/home'));
        $previews = $this->capture('TaskListEnvelope', $this->getJson('/api/v1/rider/pickup-jobs'));
        $this->key();
        $this->capture('DutyCommandEnvelope', $this->patchJson('/api/v1/rider/duty', ['on_duty' => true]));
        $key = $this->key();
        $this->capture('ParcelCommandEnvelope', $this->postJson('/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim', [
            'expected_version' => $previews->json('data.items.0.version'),
        ]));
        $this->capture('CommandEnvelope', $this->getJson('/api/v1/rider/commands/'.$key));
        $this->capture('TaskListEnvelope', $this->getJson('/api/v1/rider/tasks?phase=pickup'));
        $this->capture('TaskEnvelope', $this->getJson('/api/v1/rider/tasks/pickup-'.$parcel->id));
    }

    public function test_native_connected_payloads_use_committed_messages_notices_delivery_and_cash(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->native($rider);
        $this->capture('FinalTaskEnvelope', $this->getJson('/api/v1/rider/tasks/final_mile-'.$parcel->id));
        $thread = $this->capture('ConversationListEnvelope', $this->getJson('/api/v1/rider/conversations'))->json('data.items.0.id');
        $this->key();
        $message = $this->capture('MessageCommandEnvelope', $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => 'I am at the saved address.']));
        $this->capture('MessageListEnvelope', $this->getJson('/api/v1/rider/conversations/'.$thread.'/messages'));
        $this->key();
        $this->capture('MessageReadCommandEnvelope', $this->postJson('/api/v1/rider/conversations/'.$thread.'/read', ['through_message_id' => $message->json('data.result.id')]));
        app(NotificationDeliveryService::class)->deliverPending();
        $notice = $this->capture('NotificationListEnvelope', $this->getJson('/api/v1/rider/notifications'))->json('data.items.0.id');
        $this->key();
        $this->capture('NotificationReadCommandEnvelope', $this->postJson('/api/v1/rider/notifications/'.$notice.'/read', []));
        $this->key();
        $this->capture('DeliveredCommandEnvelope', $this->postJson('/api/v1/rider/tasks/final_mile-'.$parcel->id.'/deliver', [
            'expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'barcode' => $parcel->tracking_number,
            'recipient_name' => $parcel->order->recipient_name, 'recipient_relationship' => 'buyer',
            'cash_received' => (string) $parcel->order->total_amount, 'change_given' => '0.00', 'cash_confirmed' => true,
            'proof_image_file' => UploadedFile::fake()->image('handoff.png'),
        ]));
        $trip = $this->capture('TripListEnvelope', $this->getJson('/api/v1/rider/trips'))->json('data.items.0.id');
        $this->capture('TripEnvelope', $this->getJson('/api/v1/rider/trips/'.$trip));
        $account = CodAccount::where('delivery_id', $parcel->id)->sole();
        $this->capture('CashListEnvelope', $this->getJson('/api/v1/rider/cash'));
        $this->capture('CashEnvelope', $this->getJson('/api/v1/rider/cash/'.$account->id));
        $handler = $this->flowHandler($parcel->destinationBayanHub);
        $this->key();
        $this->capture('CashOfferCommandEnvelope', $this->postJson('/api/v1/rider/cash/'.$account->id.'/offer', [
            'expected_version' => (string) $account->version, 'recipient_id' => (string) $handler->id,
            'amount' => (string) $parcel->order->total_amount, 'evidence_reference' => 'RIDER-CASH-001',
        ]));
    }
}
