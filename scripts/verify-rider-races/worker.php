<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/config.php';
riderRaceConfigure();
$connection = DB::selectOne('select pg_backend_pid() as pid');
$barrier = '/race-work/'.$input['barrier'];
touch($barrier.'-'.$input['slot'].'.ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier.'.go')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Race barrier timed out.');
    }
    usleep(10000);
}
$files = [];
$temporary = null;
if (isset($input['image_bytes'])) {
    $temporary = tempnam('/race-work', 'proof-');
    file_put_contents($temporary, base64_decode($input['image_bytes'], true));
    $files['proof_image_file'] = new UploadedFile($temporary, 'proof.png', 'image/png', null, true);
}
$request = Request::create($input['uri'], 'POST', $input['body'], [], $files, [
    'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
    'HTTP_IDEMPOTENCY_KEY' => $input['key'],
]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
if ($temporary) {
    unlink($temporary);
}
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'backend_pid' => (int) $connection->pid], JSON_THROW_ON_ERROR);
