<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Courier\RiderApiInput;
use App\Services\Courier\RiderCommandService;
use App\Services\Courier\RiderTaskService;
use App\Services\Finance\CodCashService;
use App\Services\Notifications\NotificationCenterService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RiderNotificationController extends Controller
{
    public function __construct(private readonly NotificationCenterService $notices, private readonly RiderCommandService $commands) {}

    public function index(Request $request)
    {
        abort_unless(Schema::hasTable('notifications'), 503);
        $actor = $this->notices->current($request->user());
        $page = RiderApiInput::page($request);
        $rows = $actor->notifications()->whereIn('type', NotificationCenterService::TYPES)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) $page['per_page'], ['*'], 'page', (int) $page['page']);

        return response()->json(['data' => ['items' => $rows->getCollection()->map(fn ($notice) => $this->present($notice, $request))->all(),
            'unread_count' => $actor->notifications()->whereIn('type', NotificationCenterService::TYPES)->whereNull('read_at')->count(),
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]]]);
    }

    private function present(DatabaseNotification $notice, Request $request): array
    {
        $presented = $this->notices->present($notice, $request->user());
        $target = null;
        $data = $notice->data;
        if (($data['target'] ?? null) === 'courier-assignments' && isset($data['delivery_id'])) {
            foreach (['pickup', 'final_mile'] as $phase) {
                try {
                    $reference = $phase.'-'.RiderApiInput::id((string) $data['delivery_id']);
                    app(RiderTaskService::class)->task($request->user(), $reference);
                    $target = ['kind' => 'task', 'id' => $reference, 'href' => '/api/v1/rider/tasks/'.$reference];
                    break;
                } catch (ModelNotFoundException|HttpException) {
                }
            }
        } elseif (($data['target'] ?? null) === 'cod-cash' && isset($data['cod_account_id'])) {
            try {
                $account = app(CodCashService::class)->account($request->user(), RiderApiInput::id((string) $data['cod_account_id']));
                $target = ['kind' => 'cash', 'id' => (string) $account->id, 'href' => '/api/v1/rider/cash/'.$account->id];
            } catch (ModelNotFoundException|HttpException) {
            }
        } elseif (in_array($data['target'] ?? null, ['account-status', 'identity-correction'], true)) {
            $target = ['kind' => 'account', 'id' => (string) $request->user()->id, 'href' => '/api/v1/rider/me'];
        }

        return ['id' => $notice->id, 'type' => $notice->type, 'title' => $presented['data']['title'] ?? null,
            'body' => $presented['data']['body'] ?? null, 'recorded_at' => RiderApiInput::time($notice->created_at),
            'read_at' => RiderApiInput::time($notice->read_at), 'target' => $target];
    }

    public function read(Request $request, string $notice)
    {
        RiderApiInput::body($request, []);
        abort_unless(Str::isUuid($notice), 404);

        return $this->acknowledge($request, [strtolower($notice)]);
    }

    public function readThrough(Request $request)
    {
        $input = RiderApiInput::body($request, ['displayed_ids' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'displayed_ids.*' => ['required', 'string', 'uuid', 'regex:/\A[a-f0-9-]{36}\z/', 'distinct']]);
        $ids = array_map('strtolower', $input['displayed_ids']);
        sort($ids);

        return $this->acknowledge($request, $ids);
    }

    private function acknowledge(Request $request, array $ids)
    {
        abort_unless(Schema::hasTable('notifications'), 503);
        $result = $this->commands->execute($request, 'notification.read', 'notifications', ['displayed_ids' => $ids],
            fn () => ['read_ids' => $this->notices->acknowledgeDisplayed($request->user(), $ids)]);

        return response()->json(['data' => $result]);
    }
}
