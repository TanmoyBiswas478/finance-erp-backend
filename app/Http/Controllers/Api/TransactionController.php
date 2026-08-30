<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // 1. Database mein transaction save karo
        $transaction = Transaction::create($request->all());

        $type = $request->transaction_type;
        $amount = $request->amount;
        $sourceType = $request->source_type;
        $sourceId = $request->source_id;

        // Source find karo (Paisa kahan se nikal raha hai)
        $source = $sourceType === 'ACCOUNT' ? Account::find($sourceId) : CreditCard::find($sourceId);

        // 2. SMART CALCULATIONS
        if ($type === 'DEBIT') {
            if ($sourceType === 'ACCOUNT') {
                $source->decrement('current_balance', $amount);
            } elseif ($sourceType === 'CREDIT_CARD') {
                $source->decrement('available_limit', $amount);
                $source->increment('unbilled_outstanding', $amount); // CC ka naya outstanding badh gaya
            }
        } 
        elseif ($type === 'CREDIT') {
            if ($sourceType === 'ACCOUNT') {
                $source->increment('current_balance', $amount);
            }
        }
        elseif ($type === 'TRANSFER' || $type === 'CC_BILL') {
            // Transfer/Bill mein hamesha source (Bank) se paisa katta hai
            $source->decrement('current_balance', $amount);

            // Ab target ko find karo (Paisa kahan jaa raha hai)
            $targetType = $request->transfer_target_type;
            $targetId = $request->transfer_target_id;

            if ($targetType === 'ACCOUNT') {
                // Bank-to-Bank Transfer
                $target = Account::find($targetId);
                $target->increment('current_balance', $amount);
            } elseif ($targetType === 'CREDIT_CARD') {
                // Credit Card Bill Payment
                $target = CreditCard::find($targetId);
                $target->increment('available_limit', $amount);
                // Agar bill pay hua hai, toh outstanding kam kardo
                if ($target->billed_outstanding >= $amount) {
                    $target->decrement('billed_outstanding', $amount);
                } else {
                    $target->billed_outstanding = 0;
                    $target->save();
                }
            }
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}