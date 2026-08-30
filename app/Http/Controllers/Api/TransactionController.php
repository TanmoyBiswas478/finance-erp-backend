<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\CreditCard;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // 1. DATA MAPPING (YAHI ASLI PROBLEM THI)
        // Phone abhi bhi purane keys bhej rahe hain, hum unko naye columns mein map kar rahe hain
        $type = strtoupper(trim($request->type ?? $request->transaction_type ?? ''));
        $sourceNameOriginal = trim($request->source ?? $request->source_id ?? '');
        $date = $request->date ?? $request->transaction_date ?? now();
        $amount = (float) $request->amount;
        $sourceType = strtoupper(trim($request->source_type ?? 'ACCOUNT'));

        // 2. Database mein sahi mapped data save karo
        $transactionData = $request->all();
        $transactionData['type'] = $type;
        $transactionData['source'] = $sourceNameOriginal;
        $transactionData['date'] = $date;
        
        $transaction = Transaction::create($transactionData);

        // 3. Aliases (Jiske multiple naam ho sakte hain)
        $bankAliases = [
            'federal' => 'Jupiter',
            'jupiter' => 'Jupiter',
            'hdfc'    => 'HDFC',
            'bandhan' => 'Bandhan',
            'slice'   => 'Slice'
        ];

        $cardAliases = [
            'utkarsh'    => 'Utkarsh SuperMoney',
            'supermoney' => 'Utkarsh SuperMoney',
            'slice cc'   => 'Slice CC',
            'bandhan cc' => 'Bandhan CC'
        ];

        // 4. Source find karo
        $source = null;
        if ($sourceType === 'ACCOUNT') {
            $resolvedName = $bankAliases[strtolower($sourceNameOriginal)] ?? $sourceNameOriginal;
            $source = Account::whereRaw('LOWER(bank_name) = ?', [strtolower($resolvedName)])->first();
        } elseif ($sourceType === 'CREDIT_CARD') {
            $resolvedName = $cardAliases[strtolower($sourceNameOriginal)] ?? $sourceNameOriginal;
            $source = CreditCard::whereRaw('LOWER(card_name) = ?', [strtolower($resolvedName)])->first();
        }

        // 5. Balance update karo (Ab 100% deduct hoga)
        if ($source) {
            if ($type === 'DEBIT' || $type === 'EXPENSE') { 
                if ($sourceType === 'ACCOUNT') {
                    $source->decrement('current_balance', $amount);
                } elseif ($sourceType === 'CREDIT_CARD') {
                    $source->decrement('available_limit', $amount);
                    $source->increment('unbilled_outstanding', $amount); 
                }
            } 
            elseif ($type === 'CREDIT' || $type === 'INCOME') {
                if ($sourceType === 'ACCOUNT') {
                    $source->increment('current_balance', $amount);
                }
            }
            elseif ($type === 'TRANSFER' || $type === 'CC_BILL') {
                $source->decrement('current_balance', $amount);

                $targetType = strtoupper(trim($request->transfer_target_type ?? ''));
                $targetId = $request->transfer_target_id; 

                if ($targetType === 'ACCOUNT') {
                    $target = Account::find($targetId);
                    if ($target) {
                        $target->increment('current_balance', $amount);
                    }
                } elseif ($targetType === 'CREDIT_CARD') {
                    $target = CreditCard::find($targetId);
                    if ($target) {
                        $target->increment('available_limit', $amount);
                        if ($target->billed_outstanding >= $amount) {
                            $target->decrement('billed_outstanding', $amount);
                        } else {
                            $target->billed_outstanding = 0;
                            $target->save();
                        }
                    }
                }
            }
        } else {
            Log::error("Bank nahi mila check karo: '" . $sourceNameOriginal . "'");
        }

        return response()->json(['status' => 'success', 'data' => $transaction]);
    }
}