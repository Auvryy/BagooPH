<?php

use App\Models\CodAccount;
use App\Models\DeliveryAttempt;
use App\Models\RiderCommand;
use App\Models\User;
use App\Services\Courier\RiderTaskService;
use App\Services\RiderAccountService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

// Outside the ordinary SQLite suite; requires explicit disposable PostgreSQL approval.
class RiderPostgresRaceTest extends TestCase
{
    use CreatesE2EOrders;
    use InteractsWithOrderActions;
    use InteractsWithRoles;

    public function createApplication()
    {
        if (getenv('RIDER_RACE_APPROVED') !== 'disposable-only') {
            throw new RuntimeException('Explicit disposable PostgreSQL approval is required.');
        }
        $app = parent::createApplication();
        require_once __DIR__.'/config.php';
        riderRaceConfigure();

        return $app;
    }

    private function intent(User $rider, string $uri, array $body, ?string $key = null, ?string $image = null): array
    {
        return ['uri' => $uri, 'body' => $body, 'key' => $key ?? (string) Str::uuid(),
            'token' => app(RiderAccountService::class)->session($rider, 'Isolated race verification')['token'],
            ...($image ? ['image_bytes' => $image] : [])];
    }

    private function race(array $left, array $right): array
    {
        $barrier = 'race-'.Str::uuid();
        $jobs = [];
        foreach ([$left, $right] as $slot => $input) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __DIR__.'/worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fwrite($pipes[0], json_encode($input + ['barrier' => $barrier, 'slot' => $slot], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $jobs[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (! is_file('/race-work/'.$barrier.'-0.ready') || ! is_file('/race-work/'.$barrier.'-1.ready')) {
            if (microtime(true) >= $deadline) {
                foreach ($jobs as [$process]) {
                    proc_terminate($process);
                }
                $this->fail('Independent database workers did not reach the barrier.');
            }
            usleep(10000);
        }
        touch('/race-work/'.$barrier.'.go');
        $results = [];
        foreach ($jobs as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertNotSame($results[0]['backend_pid'], $results[1]['backend_pid']);
        file_put_contents('/race-work/race-results.jsonl', json_encode($results, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);

        return $results;
    }

    public function test_independent_postgres_claims_duplicates_and_outcome_conflicts(): void
    {
        $this->assertSame([], DB::select("select tablename from pg_tables where schemaname = 'public'"), 'Never migrate or reset an existing database.');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $constraint = DB::selectOne("select condeferrable, condeferred from pg_constraint where conname = 'rider_commands_actor_id_foreign'");
        $this->assertTrue($constraint->condeferrable);
        $this->assertTrue($constraint->condeferred);
        $parcel = $this->newFlowOrder('ready_for_pickup')->delivery;
        $left = $this->flowRider($parcel->originBayanHub, $this->createApprovedUser('courier'));
        $right = $this->flowRider($parcel->originBayanHub, $this->createApprovedUser('courier'));
        $body = ['expected_version' => app(RiderTaskService::class)->version($parcel)];
        $uri = '/api/v1/rider/pickup-jobs/'.$parcel->id.'/claim';
        $results = $this->race($this->intent($left, $uri, $body), $this->intent($right, $uri, $body));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertContains($parcel->fresh()->courier_id, [$left->id, $right->id]);
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'assigned_pickup')->count());
        $this->assertSame(1, RiderCommand::count());

        $same = $this->newFlowOrder('ready_for_pickup')->delivery;
        $key = (string) Str::uuid();
        $intent = $this->intent($left, '/api/v1/rider/pickup-jobs/'.$same->id.'/claim', ['expected_version' => app(RiderTaskService::class)->version($same)], $key);
        $duplicates = $this->race($intent, $intent);
        $this->assertSame([200, 200], array_column($duplicates, 'status'));
        $this->assertSame($duplicates[0]['body']['data']['result'], $duplicates[1]['body']['data']['result']);
        $this->assertSame(1, RiderCommand::where('actor_id', $left->id)->where('idempotency_key', $key)->count());
        $this->assertSame(1, $same->checkpoints()->where('checkpoint_type', 'assigned_pickup')->count());
        $this->withToken($intent['token'])->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.result', $duplicates[0]['body']['data']['result']);

        foreach ([false, true] as $conflicting) {
            $outcome = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
            $rider = User::findOrFail($outcome->assigned_rider_id);
            $image = UploadedFile::fake()->image('handoff.png');
            $bytes = base64_encode(file_get_contents($image->getRealPath()));
            $base = ['expected_version' => app(RiderTaskService::class)->version($outcome), 'barcode' => $outcome->tracking_number];
            $deliver = $this->intent($rider, '/api/v1/rider/tasks/final_mile-'.$outcome->id.'/deliver', $base + [
                'recipient_name' => $outcome->order->recipient_name, 'recipient_relationship' => 'buyer',
                'cash_received' => (string) $outcome->order->total_amount, 'change_given' => '0.00', 'cash_confirmed' => true,
            ], image: $bytes);
            $second = $conflicting ? $this->intent($rider, '/api/v1/rider/tasks/final_mile-'.$outcome->id.'/fail', $base + [
                'reason' => 'customer_unreachable', 'notes' => 'Called at the saved address without an answer.', 'location_name' => 'Saved destination',
            ], image: $bytes) : $deliver;
            $results = $this->race($deliver, $second);
            $statuses = array_column($results, 'status');
            sort($statuses);
            if ($conflicting) {
                $this->assertSame(200, $statuses[0]);
                // A terminal task can disappear before the losing request's first read.
                $this->assertContains($statuses[1], [404, 409]);
                $rejected = $results[0]['status'] === 200 ? $results[1] : $results[0];
                $this->assertContains($rejected['body']['code'], ['NOT_FOUND', 'STALE_RESOURCE']);
            } else {
                $this->assertSame([200, 200], $statuses);
            }
            $this->assertSame(1, $outcome->checkpoints()->whereIn('checkpoint_type', ['delivered', 'delivery_failed'])->count());
            $delivered = $outcome->fresh()->status === 'delivered';
            $this->assertSame($delivered ? 1 : 0, CodAccount::where('delivery_id', $outcome->id)->count());
            $this->assertSame($delivered ? 0 : 1, DeliveryAttempt::where('delivery_id', $outcome->id)->count());
            if ($delivered) {
                $account = CodAccount::where('delivery_id', $outcome->id)->sole();
                $this->assertSame(1, $account->events()->count());
                $this->assertSame('delivered', $outcome->order->fresh()->status);
            }
            if (! $conflicting) {
                $this->assertSame($results[0]['body']['data']['result'], $results[1]['body']['data']['result']);
                $this->withToken($deliver['token'])->getJson('/api/v1/rider/commands/'.$deliver['key'])->assertOk()
                    ->assertJsonPath('data.result', $results[0]['body']['data']['result']);
            }
        }
    }
}
