<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth; // NAYA IMPORT (Auth facade ke liye)

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

    // 2. MANUAL ENTRY ADD KARNA 
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

            // Auth::id() use kiya VS Code warnings hatane ke liye
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
                        $card->increment('available_limit', $request->amount);
                        $card->decrement('unbilled_outstanding', min($request->amount, $card->unbilled_outstanding));
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

    // 3. TRANSACTION DELETE KARNA (int type hint add kar diya!)
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