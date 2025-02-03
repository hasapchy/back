@extends('layouts.app')
@include('components.alert')
@section('page-title', 'Дашборды')
@section('content')
    {{-- <div class="container mx-auto p-4">
        <div x-data="{ openTab: 1 }">
            <ul class="flex border-b">
                <li class="-mb-px mr-1">
                    <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': openTab === 1 }" @click="openTab = 1"
                        class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                        href="#">Показатели</a>
                </li>
                <li class="-mb-px mr-1">
                    <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': openTab === 2 }" @click="openTab = 2"
                        class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                        href="#">Товары</a>
                </li>
                <li class="-mb-px mr-1">
                    <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': openTab === 3 }" @click="openTab = 3"
                        class="inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                        href="#">Услуги</a>
                </li>
            </ul>
            <div> --}}
    {{-- <div x-show="openTab === 1"> --}}
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
                        <p class="text-xl {{ $cashRegister->balance > 0 ? 'text-green-500' : 'text-red-500' }}" >{{ $cashRegister->balance }}
                            {{ $cashRegister->currency->symbol }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    {{-- <div x-show="openTab === 2">
                    @livewire('admin.products')
                </div>
                <div x-show="openTab === 3">
                    @livewire('admin.services')
                </div> --}}

    {{-- </div>
    </div> --}}
@endsection
