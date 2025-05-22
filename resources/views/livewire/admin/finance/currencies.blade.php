@section('page-title', 'Валюты')
@section('showSearch', false)
<div class="mx-auto p-4">
    @include('components.alert')
    <div class="flex items-center space-x-4 mb-4">
        <button wire:click="openForm" class="bg-[#5CB85C] text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
        
    </div>

    <div id="table-container">
        <table class="min-w-full bg-white shadow-md rounded mb-6">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 border">ID</th>
                    <th class="p-2 border">Название</th>
                    <th class="p-2 border">Код</th>
                    <th class="p-2 border">Курс</th>
                    <th class="p-2 border">Дата создания</th>
                    <th class="p-2 border">Дата обновления</th>
                    <th class="p-2 border">Валюта расчетов</th>
                    <th class="p-2 border">Валюта отчетов</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($currencies as $currency)
                    <tr wire:click="edit({{ $currency->id }})">
                        <td class="p-2 border">{{ $currency->id }}</td>
                        <td class="p-2 border">{{ $currency->name }}</td>
                        <td class="p-2 border">{{ $currency->code }}</td>
                        <td class="p-2 border">{{ $currency->currentExchangeRate()->exchange_rate ?? '-' }}</td>
                        <td class="p-2 border">{{ $currency->created_at }}</td>
                        <td class="p-2 border">{{ $currency->updated_at }}</td>
                        <td class="p-2 border">{{ $currency->is_default ? 'Да' : 'Нет' }}</td>
                        <td class="p-2 border">{{ $currency->is_report ? 'Да' : 'Нет' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 
        transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">{{ $currencyId ? 'Редактировать' : 'Создать' }} валюту</h2>

            <div x-data="{ openTab: 1 }">
                <ul class="flex border-b mb-4">
                    <li @click="openTab = 1"
                        :class="{ 'border-blue-500 text-blue-500 border-t border-r': openTab === 1 }"
                        class="cursor-pointer p-2 border-b-2 border-l">Общее</li>
                    <li @click="openTab = 2"
                        :class="{ 'border-blue-500 text-blue-500 border-t border-r': openTab === 2 }"
                        class="cursor-pointer p-2 border-b-2 border-l">История курса</li>
                </ul>

                <div x-show="openTab === 1" class="transition-all duration-500 ease-in-out">
                    <div>
                        <label class="block mb-1">Название валюты</label>
                        <input type="text" wire:model="name" placeholder="Название валюты"
                            class="w-full p-2 mb-2 border rounded">

                        <label class="block mb-1">Код валюты</label>
                        <input type="text" wire:model="code" placeholder="Код валюты"
                            class="w-full p-2 mb-2 border rounded">

                        <label class="block mb-1">Курс</label>
                        <input type="text" wire:model="exchange_rate" placeholder="Курс"
                            class="w-full p-2 mb-2 border rounded">

                        <div class="mt-4 flex justify-start space-x-2">
                            <button wire:click="save" class="bg-[#5CB85C] text-white px-4 py-2 rounded">
                                <i class="fas fa-save"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div x-show="openTab === 2" class="transition-all duration-500 ease-in-out">
                    @if ($exchangeRateHistories)
                        <table class="min-w-full bg-white shadow-md rounded mb-6">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="p-1 border border-gray-200">Exchange Rate</th>
                                    <th class="p-1 border border-gray-200">Start Date</th>
                                    <th class="p-1 border border-gray-200">End Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($exchangeRateHistories as $history)
                                    <tr>
                                        <td class="p-1 border border-gray-200">{{ $history->exchange_rate }}</td>
                                        <td class="p-1 border border-gray-200">{{ $history->start_date }}</td>
                                        <td class="p-1 border border-gray-200">{{ $history->end_date ?? 'Present' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p>Нет передвижений</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
