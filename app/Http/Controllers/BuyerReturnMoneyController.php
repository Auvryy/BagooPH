<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Services\Returns\BuyerReturnMoneyService;
use App\Services\Returns\BuyerReturnService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BuyerReturnMoneyController extends Controller
{
    public function store(Request $request, BuyerReturnCase $case, BuyerReturnMoneyService $money)
    {
        $request->validate(['action' => ['required', Rule::in(BuyerReturnMoneyService::ACTIONS)]]);
        try {
            $money->command($request->user(), $case, $request->input('action'), $request->except(['action', 'proof']), $request->file('proof'));
        } catch (ValidationException|AuthorizationException|ModelNotFoundException|HttpExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            Log::warning('Buyer return payment update could not be saved.', ['return_id' => $case->id, 'exception_type' => $error::class]);

            return back()->withErrors(['payment' => 'This payment update could not be confirmed. Reload its saved history before retrying.']);
        }

        return redirect('/buyer-returns/'.$case->id)->with('success', 'The payment update was saved. Check its actual receipt status.');
    }

    public function proof(Request $request, BuyerReturnCase $case, BuyerReturnMoneyEvent $event, BuyerReturnService $returns)
    {
        $actor = $returns->current($request->user());
        $returns->owned($actor, $case);
        abort_unless($event->buyer_return_case_id === $case->id && $event->proof_path
            && ($actor->isAdmin() || $actor->isSeller() && ! in_array($event->event_type, ['offer-funding', 'receive-funding'], true)
                || $actor->isBuyer() && in_array($event->event_type, ['record-repayment', 'resolve-nonreceipt'], true)), 404);
        $disk = Storage::disk('local');
        abort_unless(preg_match('/\Abuyer-return-money\/[a-f0-9-]+\.(?:jpg|png|webp|pdf)\z/', $event->proof_path)
            && $disk->exists($event->proof_path) && hash_equals($event->proof_hash, hash('sha256', $disk->get($event->proof_path))), 404,
            'The private receipt is unavailable. Its original record is retained.');

        return $disk->response($event->proof_path, 'Return-payment-'.$event->reference.'.'.pathinfo($event->proof_path, PATHINFO_EXTENSION),
            ['Content-Type' => $event->proof_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'attachment');
    }
}
