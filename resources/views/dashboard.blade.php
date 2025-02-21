@extends('layouts.app')
@include('components.alert')
@section('page-title', 'Дашборды')
@section('content')

    @include('components.products-accordion')
    <div class="container mx-auto p-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="shadow-md rounded-lg p-4">
                <div class="text-lg font-semibold">Сумма продаж за сегодня</div>
                <div class="text-2xl mt-2">{{ $totalSalesToday }} ТМТ</div>
            </div>
            <div class="shadow-md rounded-lg p-4">
                <div class="text-lg font-semibold">Сумма расходов за сегодня</div>
                <div class="text-2xl mt-2">{{ $totalExpensesToday }} ТМТ</div>
            </div>

            @foreach ($cashRegisters as $cashRegister)
                <div class="shadow-md rounded-lg p-4">
                    <div class="text-lg font-semibold">Деньги в кассe <span
                            class="font-bold">{{ $cashRegister->name }}</span></div>
                    <div class="mt-2">
                        <p class="text-xl {{ $cashRegister->balance > 0 ? 'text-green-500' : 'text-red-500' }}">
                            {{ $cashRegister->balance }}
                            {{ $cashRegister->currency->symbol }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
