@section('page-title', 'Кассы')
<div class="mx-auto p-4 container">
    <x-alert />

    <div class="flex items-center space-x-4 mb-4">
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
        @include('components.finance-accordion')
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="border p-2">Название</th>
                <th class="border p-2">Баланс</th>
                <th class="border p-2">Валюта</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($cashRegisters as $cashRegister)
                <tr wire:click="openForm({{ $cashRegister->id }})" class="cursor-pointer hover:bg-gray-100">
                    <td class="border p-2">{{ $cashRegister->name }}</td>
                    <td class="border p-2">{{ $cashRegister->balance }}</td>
                    <td class="border p-2">{{ $cashRegister->currency->code }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="transactionModalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">

        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            {{-- @include('components.confirmation-modal') --}}
            <h2 class="text-xl font-bold mb-4">
                {{ $cashId ? 'Редактировать кассу' : 'Создать кассу' }}</h2>
            <div class="mb-2">
                <label>Название</label>
                <input type="text" wire:model="name" class="w-full p-2 border rounded">
            </div>
            @if (!$cashId)
                <div class="mb-2">
                    <label>Баланс</label>
                    <input type="text" wire:model="balance" class="w-full p-2 border rounded">
                </div>
                <div class="mb-2">
                    <label>Валюта</label>
                    <select wire:model="currencyId" class="w-full p-2 border rounded">
                        <option value="">Выберите валюту</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->id }}">{{ $currency->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="mb-2">
                <label>Пользователи</label>
                @foreach ($allUsers as $user)
                    <div>
                        <input type="checkbox" wire:model="cashUsers" value="{{ $user->id }}">
                        <label>{{ $user->name }}</label>
                    </div>
                @endforeach
            </div>
            <div class="mt-4">
                <button wire:click="{{ $cashId ? 'update' : 'create' }}"
                    class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save "></i>
                </button>
                @if ($cashId)
                    <button wire:click="delete({{ $cashId }})"
                        class="bg-red-500 text-white px-4 py-2 rounded ml-2">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
