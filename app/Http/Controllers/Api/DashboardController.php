<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\Transaction;
use App\Models\Emi;
use App\Models\CategoryBudget; // Ise import kiya
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth; // NAYA IMPORT: Auth ke liye

class DashboardController extends Controller
{
    public function index()
    {
        $userId = Auth::id(); // Security: Current logged-in user ki ID
        
        $currentMonth = Carbon::now()->month;
        $currentYear = Carbon::now()->year;
        
        // 1. Sirf is user ke Accounts aur Cards
        $accounts = Account::where('user_id', $userId)->get();
        $creditCards = CreditCard::where('user_id', $userId)->get();
        
        // 2. Current Month Expense (Sirf is user ka)
        $currentMonthExpense = Transaction::where('user_id', $userId)
            ->whereIn('type', ['DEBIT', 'EXPENSE'])
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount');
            
        // 3. Current Month Income (Sirf is user ki)
        $currentMonthIncome = Transaction::where('user_id', $userId)
            ->whereIn('type', ['CREDIT', 'INCOME'])
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount');
            
        // 4. Recent Transactions (Sirf is user ke)
        $recent_transactions = Transaction::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function($t) {
                return [
                    'id'       => $t->id,
                    'date'     => $t->date ?? $t->transaction_date,
                    'category' => $t->category ?? 'Uncategorized',
                    'note'     => $t->description ?? $t->raw_sms ?? '--',
                    'type'     => $t->type ?? $t->transaction_type,
                    'amount'   => $t->amount
                ];
            });

        // 5. Category Expenses (Sirf is user ke)
        $category_expenses = Transaction::where('user_id', $userId)
            ->whereIn('type', ['DEBIT', 'EXPENSE'])
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        // 6. Active EMIs & Budgets (Sirf is user ke)
        $active_emis = Emi::where('user_id', $userId)->where('is_active', true)->get();
        $budgets = CategoryBudget::where('user_id', $userId)->get();
        $budget_alerts = [];

        foreach ($budgets as $budget) {
            $spent = 0;
            foreach ($category_expenses as $expense) {
                if (trim(strtolower($expense->category)) === trim(strtolower($budget->category_name))) {
                    $spent = $expense->total;
                    break;
                }
            }
            $percentage = $budget->budget_limit > 0 ? ($spent / $budget->budget_limit) * 100 : 0;
            $budget_alerts[] = [
                'category'   => $budget->category_name,
                'limit'      => $budget->budget_limit,
                'spent'      => $spent,
                'percentage' => min($percentage, 100),
                'is_danger'  => $percentage >= 90,
                'is_warning' => $percentage >= 75 && $percentage < 90
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'accounts'              => $accounts,
                'credit_cards'          => $creditCards,
                'current_month_expense' => $currentMonthExpense,
                'current_month_income'  => $currentMonthIncome,
                'recent_transactions'   => $recent_transactions,
                'category_expenses'     => $category_expenses,
                'active_emis'           => $active_emis,
                'budget_alerts'         => $budget_alerts 
            ]
        ], 200);
    }
    
    public function generateStatement($id)
    {
        // 🔒 Security: Check karo ki card is user ka hi ho
        $card = CreditCard::where('user_id', Auth::id())->findOrFail($id);
        $card->billed_outstanding += $card->unbilled_outstanding;
        $card->unbilled_outstanding = 0;
        $card->save();
        return response()->json(['status' => 'success', 'message' => 'Statement Generated Successfully!']);
    }

    public function payEmi($id)
    {
        // 🔒 Security: Check karo ki EMI is user ki hi ho
        $emi = Emi::where('user_id', Auth::id())->findOrFail($id);
        if($emi->paid_installments >= $emi->total_installments) {
            return response()->json(['status' => 'error', 'message' => 'EMI already fully paid']);
        }

        $sourceName = 'Unknown';
        if ($emi->source_type === 'ACCOUNT') {
            $source = Account::where('user_id', Auth::id())->find($emi->source_id);
            if ($source) {
                $source->decrement('current_balance', $emi->emi_amount);
                $sourceName = $source->bank_name;
            }
        } elseif ($emi->source_type === 'CREDIT_CARD') {
            $source = CreditCard::where('user_id', Auth::id())->find($emi->source_id);
            if ($source) {
                $source->decrement('available_limit', $emi->emi_amount);
                $source->increment('unbilled_outstanding', $emi->emi_amount);
                $sourceName = $source->card_name;
            }
        }

        // 🔒 EMI payment ka transaction bhi is user ke naam pe save hoga
        Transaction::create([
            'user_id'     => Auth::id(),
            'date'        => \Carbon\Carbon::now()->format('Y-m-d'),
            'amount'      => $emi->emi_amount,
            'type'        => 'EXPENSE',
            'category'    => 'EMI Payment',
            'source_type' => $emi->source_type,
            'source'      => $sourceName,
            'description' => $emi->emi_name . ' - Installment ' . ($emi->paid_installments + 1),
        ]);

        $emi->increment('paid_installments');
        if($emi->paid_installments >= $emi->total_installments) {
            $emi->is_active = false;
        }
        $emi->save();

        return response()->json(['status' => 'success', 'message' => 'EMI Paid Successfully!']);
    }
    // ==========================================
    // ACCOUNTS MANUAL CRUD (Update & Delete)
    // ==========================================

    public function updateAccount(Request $request, $id)
    {
        // Security: Ensure account belongs to the logged-in user
        $account = Account::where('user_id', Auth::id())->findOrFail($id);
        
        $request->validate([
            'bank_name' => 'required|string',
            'account_role' => 'required|string',
            'current_balance' => 'required|numeric'
        ]);

        $account->update($request->only(['bank_name', 'account_role', 'current_balance']));

        return response()->json([
            'status' => 'success',
            'message' => 'Account updated successfully',
            'data' => $account
        ]);
    }

    public function deleteAccount($id)
    {
        $account = Account::where('user_id', Auth::id())->findOrFail($id);
        $account->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Account deleted successfully'
        ]);
    }

    // ==========================================
    // CREDIT CARDS MANUAL CRUD (Update & Delete)
    // ==========================================

    public function updateCreditCard(Request $request, $id)
    {
        // Security: Ensure card belongs to the logged-in user
        $card = CreditCard::where('user_id', Auth::id())->findOrFail($id);

        $request->validate([
            'card_name' => 'required|string',
            'total_limit' => 'required|numeric',
            'available_limit' => 'required|numeric',
            'billed_outstanding' => 'required|numeric',
            'unbilled_outstanding' => 'required|numeric',
            'billing_date' => 'required|integer'
        ]);

        $card->update($request->all());

        return response()->json([
            'status' => 'success',
            'message' => 'Credit card updated successfully',
            'data' => $card
        ]);
    }

    public function deleteCreditCard($id)
    {
        $card = CreditCard::where('user_id', Auth::id())->findOrFail($id);
        $card->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Credit card deleted successfully'
        ]);
    }

}