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
        $totalSalesToday = Sale::whereBetween('created_at', [
            now()->startOfDay()->toDateTimeString(),
            now()->endOfDay()->toDateTimeString()
        ])->sum('total_price');
        $totalExpensesToday = Transaction::where('type', 0) // 0 for expense
            ->whereBetween('date', [
                now()->startOfDay()->toDateTimeString(),
                now()->endOfDay()->toDateTimeString()
            ])
            ->sum('amount');
        $cashRegisters = CashRegister::all();

        return view('dashboard', compact('totalSalesToday', 'totalExpensesToday', 'cashRegisters'));
    }
}
