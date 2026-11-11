<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Services\Orders\OrderCancellationService;
use App\Services\Orders\OrderLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class OrderCancellationController extends Controller
{
    public function store(Request $request, Order $order, OrderCancellationService $service): JsonResponse|RedirectResponse
    {
        return $this->run($request, $order, fn () => $service->submit($request->user(), $order, $request->all()));
    }

    public function decide(Request $request, Order $order, OrderCancellationRequest $cancellation, string $decision, OrderCancellationService $service): JsonResponse|RedirectResponse
    {
        return $this->run($request, $order, fn () => $service->decide($request->user(), $order, $cancellation, $decision, $request->all()));
    }

    private function run(Request $request, Order $order, callable $command): JsonResponse|RedirectResponse
    {
        try {
            $result = $command();
        } catch (ValidationException|AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [409, 503], true)) {
                throw $error;
            }

            return $this->error($request, $error->getMessage(), $error->getStatusCode());
        } catch (Throwable $error) {
            Log::warning('Order cancellation review could not be saved.', ['order_id' => $order->id, 'exception_type' => $error::class]);

            return $this->error($request, 'The cancellation update could not be confirmed. Reload its saved status before retrying.', 500);
        }
        $presentation = app(OrderCancellationService::class)->present($order->fresh(), $result);
        $message = match ($presentation['status']) {
            'pending' => 'Cancellation requested. The seller has 24 hours to respond; your order is not cancelled yet.',
            'approved' => 'Cancellation approved. The order is cancelled; review its recorded payment outcome.',
            'rejected' => 'Cancellation declined. The order remains active and the reason was saved.',
            'expired' => 'The cancellation request expired. The order and payment are unchanged.',
            default => 'This cancellation request is closed. Review the order’s current status.',
        };
        if ($request->expectsJson()) {
            $order = $order->fresh();

            return response()->json(['message' => $message, 'order' => $order->only(['id', 'order_number', 'status', 'payment_status']),
                'cancellation' => $presentation,
                'cancellation_feedback' => app(OrderLifecycleService::class)->cancellationFeedback($order,
                    $request->user()->isBuyer() ? $request->user() : null)], $result->wasRecentlyCreated ? 201 : 200);
        }

        return back()->with('success', $message);
    }

    private function error(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $message, 'errors' => ['cancellation_request' => [$message]]], $status)
            : back()->withErrors(['cancellation_request' => $message]);
    }
}
