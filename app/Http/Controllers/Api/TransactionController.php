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

        // Naye columns ke hisaab se request data nikalo
        $type = $request->type; // transaction_type ki jagah type
        $amount = $request->amount;
        $sourceType = $request->source_type;
        $sourceName = $request->source; // source_id ki jagah seedha source (naam)

        // Source find karo (Ab ID se nahi, Bank/Card ke naam se dhoondhenge)
        $source = null;
        if ($sourceType === 'ACCOUNT') {
            $source = Account::where('bank_name', $sourceName)->first();
        } elseif ($sourceType === 'CREDIT_CARD') {
            $source = CreditCard::where('card_name', $sourceName)->first();
        }

        // Agar account/card database mein mil gaya, tabhi balance update karo
        if ($source) {
            // 2. SMART CALCULATIONS
            if ($type === 'DEBIT' || $type === 'EXPENSE') { // MacroDroid 'EXPENSE' bhejta hai
                if ($sourceType === 'ACCOUNT') {
                    $source->decrement('current_balance', $amount);
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $source->decrement('available_limit', $amount);
                    $source->increment('unbilled_outstanding', $amount); // CC ka naya outstanding badh gaya
                }
            } 
            elseif ($type === 'CREDIT' || $type === 'INCOME') {
                if ($sourceType === 'ACCOUNT') {
                    $source->increment('current_balance', $amount);
                }
            }
            elseif ($type === 'TRANSFER' || $type === 'CC_BILL') {
                // Transfer/Bill mein hamesha source (Bank) se paisa katta hai
                $source->decrement('current_balance', $amount);

                // Ab target ko find karo (Paisa kahan jaa raha hai)
                $targetType = $request->transfer_target_type;
                $targetId = $request->transfer_target_id; // Yeh frontend se aayega, toh id theek hai

                if ($targetType === 'ACCOUNT') {
                    // Bank-to-Bank Transfer
                    $target = Account::find($targetId);
                    if ($target) {
                        $target->increment('current_balance', $amount);
                    }
                } elseif ($targetType === 'CREDIT_CARD') {
                    // Credit Card Bill Payment
                    $target = CreditCard::find($targetId);
                    if ($target) {
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
            }
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}