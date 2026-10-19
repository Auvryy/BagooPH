<?php

namespace App\Http\Controllers;

use App\Models\BuyerReturnCase;
use App\Services\Returns\BuyerReturnCustodyService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class BuyerReturnCustodyController extends Controller
{
    public function store(Request $request, BuyerReturnCase $case, BuyerReturnCustodyService $custody): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['prepare-label', 'intake', 'stage', 'seller-receive'])]]);
        try {
            $custody->command($request->user(), $case, $data['action'], $request->except('photo'), $request->file('photo'));
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (DomainException $error) {
            return back()->withErrors(['custody' => $error->getMessage()]);
        } catch (Throwable $error) {
            Log::warning('Buyer return custody could not be saved.', ['return_id' => $case->id, 'exception_type' => $error::class]);

            return back()->withErrors(['custody' => 'This return handover could not be confirmed. Reload its retained history before retrying.']);
        }

        return redirect('/buyer-returns/'.$case->id)->with('success', 'The actual return update was saved. Repayment remains a separate step.');
    }
}
