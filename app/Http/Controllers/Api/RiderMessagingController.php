<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Rules\ApplicationText;
use App\Services\Courier\CourierMessagingService;
use App\Services\Courier\RiderApiInput;
use App\Services\Courier\RiderCommandService;
use App\Services\Courier\RiderMessagingService;
use App\Services\Courier\RiderTaskService;
use App\Services\Logistics\WaybillScanInputService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiderMessagingController extends Controller
{
    public function __construct(private readonly RiderMessagingService $threads, private readonly RiderCommandService $commands) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->threads->list($request)]);
    }

    public function show(Request $request, string $thread): JsonResponse
    {
        return response()->json(['data' => $this->threads->thread($request, $thread)]);
    }

    public function send(Request $request, string $thread): JsonResponse
    {
        if (is_string($request->input('text'))) {
            $request->merge(['text' => app(WaybillScanInputService::class)->normalize(['notes' => $request->input('text')])['notes']]);
        }
        $input = RiderApiInput::body($request, ['text' => ['required', 'string', new ApplicationText('notes', 1, 1000)]]);
        $result = $this->commands->execute($request, 'message.send', $thread, $input, function () use ($request, $thread, $input) {
            [$assignment] = $this->threads->owned($request->user()->fresh(), $thread);
            app(RiderTaskService::class)->lock($assignment->delivery);
            [$assignment, $phase, $participant] = $this->threads->owned($request->user()->fresh(), $thread);
            $message = app(CourierMessagingService::class)->send($request->user(), $assignment->delivery, $input['text'], $phase, $participant['user']->id, $assignment->id);

            return $this->threads->message($message, $request->user());
        });

        return response()->json(['data' => $result]);
    }

    public function read(Request $request, string $thread): JsonResponse
    {
        $input = RiderApiInput::body($request, ['through_message_id' => ['required', 'string']]);
        $id = RiderApiInput::id($input['through_message_id']);
        $result = $this->commands->execute($request, 'message.read', $thread, $input, function () use ($request, $thread, $id) {
            [$assignment] = $this->threads->owned($request->user()->fresh(), $thread);
            app(RiderTaskService::class)->lock($assignment->delivery);
            [$assignment, $phase] = $this->threads->owned($request->user()->fresh(), $thread);
            app(CourierMessagingService::class)->acknowledge($request->user(), $assignment->delivery, $phase, $id);

            return ['through_message_id' => (string) $id];
        });

        return response()->json(['data' => $result]);
    }
}
