<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Transaction;
use Carbon\Carbon;
use App\Models\Emi;

class DashboardController extends Controller
{
    public function index()
    {
        // 1. Saare bank accounts ka data fetch karo
        $accounts = Account::all();
        
        // 2. Saare credit cards ka data fetch karo
        $creditCards = CreditCard::all();
        
        // Date variables set kar lo taaki baar-baar call na karna pade
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;
        
        // 3. Current month ke total expenses (DEBIT transactions) - FIXED COLUMN NAMES
        $currentMonthExpense = Transaction::where('type', 'DEBIT')
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount');
            
        // 4. Last 5 recent transactions
        $recent_transactions = Transaction::orderBy('created_at', 'desc')->limit(5)->get();

        // 5. Current month ke expenses ko category ke hisaab se group karo - FIXED COLUMN NAMES
        $category_expenses = Transaction::where('type', 'DEBIT')
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        // 6. Active EMIs fetch karo
        $active_emis = Emi::where('is_active', true)->get();

        // 7. Budget vs Spend calculate karo
        $budgets = \App\Models\CategoryBudget::all();
        $budget_alerts = [];

        foreach ($budgets as $budget) {
            $spent = 0;
            // category_expenses humne chart ke liye pehle hi nikal rakha hai, usi ko use karenge
            foreach ($category_expenses as $expense) {
                if (strtolower($expense->category) === strtolower($budget->category_name)) {
                    $spent = $expense->total;
                    break;
                }
            }

            $percentage = $budget->budget_limit > 0 ? ($spent / $budget->budget_limit) * 100 : 0;
            
            $budget_alerts[] = [
                'category' => $budget->category_name,
                'limit' => $budget->budget_limit,
                'spent' => $spent,
                'percentage' => min($percentage, 100), // 100% se zyada progress bar bahar na jaye
                'is_danger' => $percentage >= 90, // 90% cross par Red Alert
                'is_warning' => $percentage >= 75 && $percentage < 90 // 75% par Orange Warning
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'accounts' => $accounts,
                'credit_cards' => $creditCards,
                'current_month_expense' => $currentMonthExpense,
                'recent_transactions' => $recent_transactions,
                'category_expenses' => $category_expenses,
                'active_emis' => $active_emis,
                'budget_alerts' => $budget_alerts
            ]
        ], 200);
    }

    public function generateStatement($id)
    {
        $card = CreditCard::findOrFail($id);
        
        // Unbilled amount ko Billed mein shift karo
        $card->billed_outstanding += $card->unbilled_outstanding;
        $card->unbilled_outstanding = 0;
        $card->save();

        return response()->json([
            'status' => 'success', 
            'message' => 'Statement Generated Successfully!'
        ]);
    }

    public function payEmi($id)
    {
        $emi = \App\Models\Emi::findOrFail($id);
        
        if($emi->paid_installments >= $emi->total_installments) {
            return response()->json(['status' => 'error', 'message' => 'EMI already fully paid']);
        }

        // 1. Bank Account ya Credit Card se balance kaato aur unka naam nikalo
        $sourceName = 'Unknown';
        if ($emi->source_type === 'ACCOUNT') {
            $source = \App\Models\Account::find($emi->source_id);
            if ($source) {
                $source->decrement('current_balance', $emi->emi_amount);
                $sourceName = $source->bank_name;
            }
        } elseif ($emi->source_type === 'CREDIT_CARD') {
            $source = \App\Models\CreditCard::find($emi->source_id);
            if ($source) {
                $source->decrement('available_limit', $emi->emi_amount);
                $source->increment('unbilled_outstanding', $emi->emi_amount);
                $sourceName = $source->card_name;
            }
        }

        // 2. Transaction create karo - FIXED COLUMN NAMES
        \App\Models\Transaction::create([
            'date' => \Carbon\Carbon::now(), // transaction_date -> date
            'amount' => $emi->emi_amount,
            'type' => 'DEBIT', // transaction_type -> type
            'category' => 'EMI Payment',
            'source_type' => $emi->source_type,
            'source' => $sourceName, // source_id ki jagah seedha Bank/Card ka naam bhej rahe hain
            'description' => $emi->emi_name . ' - Installment ' . ($emi->paid_installments + 1),
        ]);

        // 3. EMI ka progress update karo
        $emi->increment('paid_installments');
        
        // Agar saari EMI bhar di, toh active status hata do
        if($emi->paid_installments >= $emi->total_installments) {
            $emi->is_active = false;
        }
        $emi->save();

        return response()->json(['status' => 'success', 'message' => 'EMI Paid Successfully!']);
    }
}