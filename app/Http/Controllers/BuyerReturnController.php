<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvidence;
use App\Models\Order;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BuyerReturnController extends Controller
{
    public function index(Request $request, BuyerReturnService $returns): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);
        $cases = $returns->scope($request->user())->with('order:id,order_number')->latest('id')->paginate(12)->withQueryString()
            ->through(fn ($case) => $case->only(['id', 'reference', 'status', 'reason', 'created_at'])
                + ['order_number' => $case->order->order_number]);

        return Inertia::render('Returns/Index', ['cases' => $cases]);
    }

    public function create(Request $request, Order $order, BuyerReturnService $returns): Response
    {
        return Inertia::render('Returns/Create', $returns->form($request->user(), $order));
    }

    public function store(Request $request, Order $order, BuyerReturnService $returns): RedirectResponse
    {
        try {
            $case = $returns->request($request->user(), $order, $request->except('photos'), $request->file('photos', []));
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Buyer return request could not be saved.', ['order_id' => $order->id, 'exception_type' => $error::class]);

            return back()->withErrors(['return' => 'Your return could not be saved. Keep your photos and try again; no success is confirmed yet.']);
        }

        return redirect('/buyer-returns/'.$case->id)->with('success', 'Your return request was saved. The seller can now review it.');
    }

    public function show(Request $request, BuyerReturnCase $case, BuyerReturnService $returns): Response
    {
        return Inertia::render('Returns/Show', ['returnCase' => $returns->present($request->user(), $case)]);
    }

    public function action(Request $request, BuyerReturnCase $case, BuyerReturnService $returns): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'string', Rule::in(['approve', 'reject', 'escalate', 'withdraw', 'close-period'])]]);
        try {
            $returns->command($request->user(), $case, $data['action'], $request->all());
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Buyer return decision could not be saved.', ['return_id' => $case->id, 'exception_type' => $error::class]);

            return back()->withErrors(['return' => 'This update could not be confirmed. Reload the return to check its saved history, then retry.']);
        }

        return redirect('/buyer-returns/'.$case->id)->with('success', 'The return update was saved. Check its history for the next step.');
    }

    public function evidence(Request $request, BuyerReturnCase $case, BuyerReturnEvidence $evidence, BuyerReturnService $returns): StreamedResponse
    {
        $returns->owned($request->user(), $case);
        abort_unless($evidence->buyer_return_case_id === $case->id, 404);
        $disk = Storage::disk('local');
        abort_unless(preg_match('/\Abuyer-return-evidence\/[a-f0-9-]+\.(?:jpg|png|webp)\z/', $evidence->path)
            && $disk->exists($evidence->path) && hash_equals($evidence->content_hash, hash('sha256', $disk->get($evidence->path))), 404,
            'This return photo is unavailable. Its original evidence record is retained.');

        return $disk->response($evidence->path, 'Return-'.$case->reference.'-photo-'.$evidence->id.'.'.pathinfo($evidence->path, PATHINFO_EXTENSION),
            ['Content-Type' => $evidence->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
