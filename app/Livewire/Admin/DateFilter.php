<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use Carbon\Carbon;

class DateFilter extends Component
{
    public $dateFilter = 'all_time'; 
    public $startDate;
    public $endDate;

    protected $listeners = ['applyDateFilter'];

    public function updatedDateFilter()
    {
        $this->applyDateFilter();
    }

    public function applyDateFilter()
    {
        switch ($this->dateFilter) {
            case 'today':
                $this->startDate = Carbon::today()->toDateString();
                $this->endDate = Carbon::today()->toDateString();
                break;
            case 'yesterday':
                $this->startDate = Carbon::yesterday()->toDateString();
                $this->endDate = Carbon::yesterday()->toDateString();
                break;
            case 'this_week':
                $this->startDate = Carbon::now()->startOfWeek()->toDateString();
                $this->endDate = Carbon::now()->endOfWeek()->toDateString();
                break;
            case 'this_month':
                $this->startDate = Carbon::now()->startOfMonth()->toDateString();
                $this->endDate = Carbon::now()->endOfMonth()->toDateString();
                break;
            case 'last_week':
                $this->startDate = Carbon::now()->subWeek()->startOfWeek()->toDateString();
                $this->endDate = Carbon::now()->subWeek()->endOfWeek()->toDateString();
                break;
            case 'last_month':
                $this->startDate = Carbon::now()->subMonth()->startOfMonth()->toDateString();
                $this->endDate = Carbon::now()->subMonth()->endOfMonth()->toDateString();
                break;
            case 'custom':
                break;
            case 'all_time':
                $this->startDate = null;
                $this->endDate = null;
                break;
            default:
                $this->startDate = null;
                $this->endDate = null;
                break;
        }

        $this->dispatch('dateFilterUpdated', $this->startDate, $this->endDate);
    }

    public function render()
    {
        return view('components.date-filter');
    }
}
