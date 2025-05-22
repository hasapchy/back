@section('page-title', 'Трансферы')

@section('page-links')
    <a href="{{ route('admin.finance.index') }}" class="text-blue-500 hover:underline mr-4">Финансы</a>
    <a href="{{ route('admin.cash.index') }}" class="text-blue-500 hover:underline mr-4">Кассы</a>
    <a href="{{ route('admin.templates.index') }}" class="text-blue-500 hover:underline mr-4">Шаблоны</a>
@endsection

<div class="mx-auto p-4">
    @include('components.alert')
    <div class="flex space-x-4 mb-4">

        <button wire:click="openForm" class="mb-4 bg-[#5CB85C] text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>

        
    </div>
    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>

                <th class="p-1 border border-gray-200">Касса-отправитель</th>
                <th class="p-1 border border-gray-200">Сумма</th>
                <th class="p-1 border border-gray-200">Касса-получатель</th>
                <th class="p-1 border border-gray-200">Заметка</th>
                <th class="p-1 border border-gray-200">Пользователь</th>
                <th class="p-1 border border-gray-200">Дата</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transfers as $transfer)
                <tr wire:click="edit({{ $transfer->id }})"
                    class="cursor-pointer mb-2 p-2 border rounded {{ $transferId == $transfer->id ? 'bg-gray-200' : '' }}">

                    <td class="p-1 border border-gray-200">{{ $transfer->fromCashRegister->name }}</td>
                    <td class="p-1 border border-gray-200">
                        <i class="fas fa-arrow-right text-red-500 mr-2"></i>
                        {{ $transfer->amount }}
                        <i class="fas fa-arrow-right text-green-500 ml-2"></i>
                    </td>
                    <td class="p-1 border border-gray-200">{{ $transfer->toCashRegister->name }}</td>
                    <td class="p-1 border border-gray-200">{{ $transfer->note }}</td>
                    <td class="p-1 border border-gray-200">{{ $transfer->user->name ?? '' }}</td>
                    <td class="p-1 border border-gray-200">
                        {{ \Carbon\Carbon::parse($transfer->date)->format('d.m.Y') }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Трансфер</h2>
            @include('components.confirmation-modal')

            <div class="mb-4">
                <label class="block mb-1">Дата списания</label>
                <input type="datetime-local" wire:model="date" class="w-full p-2 border rounded">
            </div>
            <div class="mb-4">
                <label class="block mb-1">От кассы</label>
                <select wire:model.change="cashFrom" class="w-full p-2 border rounded">
                    <option value="">Выберите кассу</option>
                    @foreach ($cashRegisters as $register)
                        <option value="{{ $register->id }}" @if ($register->id == $cashTo) disabled @endif>
                            {{ $register->name }} ({{ $register->currency->symbol }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4">
                <label class="block mb-1">Кому касса</label>
                <select wire:model.change="cashTo" class="w-full p-2 border rounded">
                    <option value="">Выберите кассу</option>
                    @foreach ($cashRegisters as $register)
                        <option value="{{ $register->id }}" @if ($register->id == $cashFrom) disabled @endif>
                            {{ $register->name }} ({{ $register->currency->symbol }})
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mb-4">
                <label class="block mb-1">Сумма</label>
                <input type="text" wire:model="amount" placeholder="Сумма" class="w-full p-2 border rounded">
            </div>

            <div class="mb-4">
                <label class="block mb-1">Примечание</label>
                <textarea wire:model="note" placeholder="Примечание" class="w-full p-2 border rounded"></textarea>
            </div>

            <div class="flex space-x-2">
                <button wire:click="saveTransfer" class="bg-[#5CB85C] text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if ($transferId)
                    <button wire:click="delete" class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
