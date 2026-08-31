<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class TransactionController extends Controller
{
    // 1. SAARE TRANSACTIONS DEKHNA 
    public function index(Request $request)
    {
        $transactions = Transaction::where('user_id', Auth::id())
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json(['status' => 'success', 'data' => $transactions]);
    }

    // 2. MANUAL ENTRY ADD KARNA (With Waterfall Payment for Credit Cards)
    public function store(Request $request)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:1',
            'type'        => 'required|string', 
            'category'    => 'required|string',
            'source_type' => 'required|string', 
            'source_name' => 'required|string', 
            'date'        => 'required|date'
        ]);

        try {
            DB::beginTransaction();

            $type = in_array(strtoupper($request->type), ['DEBIT', 'EXPENSE']) ? 'EXPENSE' : 'INCOME';

            $transaction = Transaction::create([
                'user_id'     => Auth::id(),
                'amount'      => $request->amount,
                'type'        => $type,
                'category'    => $request->category,
                'source_type' => strtoupper($request->source_type),
                'source'      => $request->source_name,
                'description' => $request->description ?? 'Manual Entry',
                'date'        => $request->date,
            ]);

            if (strtoupper($request->source_type) === 'ACCOUNT') {
                $account = Account::where('user_id', Auth::id())->where('bank_name', $request->source_name)->first();
                if ($account) {
                    if ($type === 'EXPENSE') {
                        $account->decrement('current_balance', $request->amount);
                    } else {
                        $account->increment('current_balance', $request->amount);
                    }
                }
            } elseif (strtoupper($request->source_type) === 'CREDIT_CARD') {
                $card = CreditCard::where('user_id', Auth::id())->where('card_name', $request->source_name)->first();
                if ($card) {
                    if ($type === 'EXPENSE') {
                        $card->decrement('available_limit', $request->amount);
                        $card->increment('unbilled_outstanding', $request->amount);
                    } else {
                        // 🌊 WATERFALL PAYMENT LOGIC FOR MANUAL PAYMENTS
                        $card->increment('available_limit', $request->amount);
                        $remaining = $request->amount;
                        
                        if ($card->billed_outstanding > 0) {
                            $deduct = min($remaining, $card->billed_outstanding);
                            $card->decrement('billed_outstanding', $deduct);
                            $remaining -= $deduct;
                        }
                        
                        if ($remaining > 0) {
                            $card->decrement('unbilled_outstanding', min($remaining, $card->unbilled_outstanding));
                        }
                    }
                }
            }

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Transaction successfully added!', 'data' => $transaction]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Manual Add Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 3. TRANSACTION UPDATE / EDIT KARNA (With Balance Reversal & Re-application)
    public function update(Request $request, int $id)
    {
        $request->validate([
            'amount'      => 'required|numeric|min:1',
            'type'        => 'required|string', 
            'category'    => 'required|string',
            'source_type' => 'required|string', 
            'source_name' => 'required|string', 
            'date'        => 'required|date'
        ]);

        try {
            DB::beginTransaction();

            $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);

            // STEP 1: Reverse old transaction effect on account/card
            if ($transaction->source_type === 'ACCOUNT') {
                $oldAccount = Account::where('user_id', Auth::id())->where('bank_name', $transaction->source)->first();
                if ($oldAccount) {
                    if ($transaction->type === 'EXPENSE') {
                        $oldAccount->increment('current_balance', $transaction->amount);
                    } else {
                        $oldAccount->decrement('current_balance', $transaction->amount);
                    }
                }
            } elseif ($transaction->source_type === 'CREDIT_CARD') {
                $oldCard = CreditCard::where('user_id', Auth::id())->where('card_name', $transaction->source)->first();
                if ($oldCard) {
                    if ($transaction->type === 'EXPENSE') {
                        $oldCard->increment('available_limit', $transaction->amount);
                        $oldCard->decrement('unbilled_outstanding', min($transaction->amount, $oldCard->unbilled_outstanding));
                    } else {
                        // Reversal of payment: reduce limit, increase unbilled/billed back
                        $oldCard->decrement('available_limit', $transaction->amount);
                        $oldCard->increment('unbilled_outstanding', $transaction->amount);
                    }
                }
            }

            // STEP 2: Prepare new values
            $newType = in_array(strtoupper($request->type), ['DEBIT', 'EXPENSE']) ? 'EXPENSE' : 'INCOME';
            $newSourceType = strtoupper($request->source_type);
            $newSourceName = $request->source_name;
            $newAmount = $request->amount;

            // STEP 3: Apply new transaction effect on account/card
            if ($newSourceType === 'ACCOUNT') {
                $newAccount = Account::where('user_id', Auth::id())->where('bank_name', $newSourceName)->first();
                if ($newAccount) {
                    if ($newType === 'EXPENSE') {
                        $newAccount->decrement('current_balance', $newAmount);
                    } else {
                        $newAccount->increment('current_balance', $newAmount);
                    }
                }
            } elseif ($newSourceType === 'CREDIT_CARD') {
                $newCard = CreditCard::where('user_id', Auth::id())->where('card_name', $newSourceName)->first();
                if ($newCard) {
                    if ($newType === 'EXPENSE') {
                        $newCard->decrement('available_limit', $newAmount);
                        $newCard->increment('unbilled_outstanding', $newAmount);
                    } else {
                        // 🌊 WATERFALL PAYMENT LOGIC
                        $newCard->increment('available_limit', $newAmount);
                        $remaining = $newAmount;
                        
                        if ($newCard->billed_outstanding > 0) {
                            $deduct = min($remaining, $newCard->billed_outstanding);
                            $newCard->decrement('billed_outstanding', $deduct);
                            $remaining -= $deduct;
                        }
                        
                        if ($remaining > 0) {
                            $newCard->decrement('unbilled_outstanding', min($remaining, $newCard->unbilled_outstanding));
                        }
                    }
                }
            }

            // STEP 4: Update transaction record
            $transaction->update([
                'amount'      => $newAmount,
                'type'        => $newType,
                'category'    => $request->category,
                'source_type' => $newSourceType,
                'source'      => $newSourceName,
                'description' => $request->description ?? $transaction->description,
                'date'        => $request->date,
            ]);

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Transaction updated and balances adjusted safely!', 'data' => $transaction]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Transaction Update Error: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    // 4. TRANSACTION DELETE KARNA
    public function destroy(int $id)
    {
        try {
            DB::beginTransaction();

            $transaction = Transaction::where('user_id', Auth::id())->findOrFail($id);

            if ($transaction->source_type === 'ACCOUNT') {
                $account = Account::where('user_id', Auth::id())->where('bank_name', $transaction->source)->first();
                if ($account) {
                    if ($transaction->type === 'EXPENSE') {
                        $account->increment('current_balance', $transaction->amount);
                    } else {
                        $account->decrement('current_balance', $transaction->amount);
                    }
                }
            } elseif ($transaction->source_type === 'CREDIT_CARD') {
                $card = CreditCard::where('user_id', Auth::id())->where('card_name', $transaction->source)->first();
                if ($card) {
                    if ($transaction->type === 'EXPENSE') {
                        $card->increment('available_limit', $transaction->amount);
                        $card->decrement('unbilled_outstanding', min($transaction->amount, $card->unbilled_outstanding));
                    } else {
                        $card->decrement('available_limit', $transaction->amount);
                        $card->increment('unbilled_outstanding', $transaction->amount);
                    }
                }
            }

            $transaction->delete();

            DB::commit();
            return response()->json(['status' => 'success', 'message' => 'Transaction deleted and balance reversed safely!']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['status' => 'error', 'message' => 'Failed to delete: ' . $e->getMessage()], 500);
        }
    }
}