<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\Transaction;
use App\Models\CashRegister;

class DashboardController extends Controller
{
    public function index()
    {
        $totalSalesToday = Sale::whereDate('created_at', today())->sum('total_price');
        $totalExpensesToday = Transaction::where('type', 0) // 0 for expense
            ->whereDate('transaction_date', today())
            ->sum('amount');
        $cashRegisters = CashRegister::all();

        return view('dashboard', compact('totalSalesToday', 'totalExpensesToday', 'cashRegisters'));
    }
}
