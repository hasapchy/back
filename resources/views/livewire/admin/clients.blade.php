@section('page-title', 'Клиенты')
@section('showSearch', true)
<div class="container mx-auto p-4">
    @include('components.alert')

    <div class="flex items-center space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_clients'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-user-plus"></i>
            </button>
        @endif
        <button id="columnsMenuButton" class="bg-gray-500 text-white px-4 py-2 rounded">
            <i class="fa fa-cogs"></i>
        </button>
        <div id="columnsMenu" class="hidden absolute bg-white shadow-md rounded p-4 z-10 mt-2">
            <h2 class="font-bold mb-2">Выберите колонки для отображения:</h2>
            @foreach ($columns as $column)
                <div class="mb-2">
                    <label>
                        <input type="checkbox" class="column-toggle" data-column="{{ $column }}" checked>
                        {{ str_replace('_', ' ', $column) }}
                    </label>
                </div>
            @endforeach
        </div>
        <div class="flex space-x-2">
            <button class="filter-button client-type px-4 py-2 rounded bg-blue-500 text-white" data-filter="all">
                Все
            </button>
            <button class="filter-button client-type px-4 py-2 rounded bg-gray-200" data-filter="individual">
                Физ. лица
            </button>
            <button class="filter-button client-type px-4 py-2 rounded bg-gray-200" data-filter="company">
                Компании
            </button>
            <button class="filter-button supplier-type px-4 py-2 rounded bg-gray-200" data-filter="supplier">
                Поставщики
            </button>
            <button class="filter-button supplier-type px-4 py-2 rounded bg-gray-200" data-filter="client">
                Покупатели
            </button>
        </div>
    </div>

    <div id="table-container" wire:ignore>
        <div id="table-skeleton" class="animate-pulse">
            <div id="skeleton-header-row" class="grid grid-cols-{{ count($columns) }}">
                @foreach ($columns as $column)
                    <div class="p-2 h-6 bg-gray-300 rounded"></div>
                @endforeach
            </div>
            @for ($i = 0; $i < 5; $i++)
                <div class="grid grid-cols-{{ count($columns) }} gap-4">
                    @foreach ($columns as $column)
                        <div class="p-2 h-6 bg-gray-200 rounded"></div>
                    @endforeach
                </div>
            @endfor
        </div>

        <div id="table" class="fade-in shadow w-full rounded-md overflow-hidden">
            <div id="header-row" class="grid grid-cols-{{ count($columns) }}">
                @foreach ($columns as $column)
                    <div class="p-2 uppercase cursor-move" data-key="{{ $column }}">
                        {{ str_replace('_', ' ', $column) }}
                    </div>
                @endforeach
            </div>

            <div id="table-body">
                @foreach ($clients as $client)
                    <div class="grid grid-cols-{{ count($columns) }}" data-client-type="{{ $client->client_type }}"
                        data-is-supplier="{{ $client->is_supplier ? 'supplier' : 'client' }}"
                        wire:click="editClient({{ $client->id }})">
                        @foreach ($columns as $column)
                            <div class="p-2" data-key="{{ $column }}">
                                @if ($column === 'first_name')
                                    @if ($client->client_type === 'company')
                                        <i class="fas fa-building"></i>
                                    @else
                                        <i class="fas fa-user"></i>
                                    @endif
                                    @if ($client->is_conflict)
                                        <i class="fas fa-angry text-red-500"></i>
                                    @endif
                                    @if ($client->is_supplier)
                                        <i class="fas fa-truck text-blue-500"></i>
                                    @endif
                                @endif
                                {{ $client->$column ?? '-' }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 
        transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">{{ $clientId ? 'Редактировать' : 'Создать' }} клиента</h2>
            <div x-data="{ activeTab: 1 }">
                <ul class="flex border-b mb-4">
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 1 }"
                            @click.prevent="activeTab = 1"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Общие</a>
                    </li>
                    @if ($clientId)
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 2 }"
                                @click.prevent="activeTab = 2"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">Баланс</a>
                        </li>
                    @endif
                </ul>

                <div x-show="activeTab === 1" class="transition-all duration-500 ease-in-out">

                    <div>
                        <label class="block mb-1">Тип клиента:</label>
                        <label class="inline-flex items-center">
                            <input type="radio" wire:model.change="client_type" value="individual" name="client_type"
                                class="form-radio"> Индивидуальный
                        </label>
                        <label class="inline-flex items-center ml-4">
                            <input type="radio" wire:model.change="client_type" value="company" name="client_type"
                                class="form-radio"> Компания
                        </label>
                    </div>

                    <input type="checkbox" wire:model="isConflict"> Конфликтный
                    <input type="checkbox" wire:model="isSupplier" class="ml-2"> Поставщик

                    <label class="block mb-1">Имя</label>
                    <input type="text" wire:model="first_name" placeholder="Имя"
                        class="w-full p-2 mb-2 border rounded">

                    @if ($client_type === 'individual')
                        <label class="block mb-1">Фамилия</label>
                        <input type="text" wire:model="last_name" placeholder="Фамилия"
                            class="w-full p-2 mb-2 border rounded">
                    @endif

                    @if ($client_type === 'company')
                        <label class="block mb-1">Контактное лицо</label>
                        <input type="text" wire:model="contact_person" placeholder="Контактное лицо"
                            class="w-full p-2 mb-2 border rounded">
                    @endif


                    <label class="block mb-1">Адрес</label>
                    <input type="text" wire:model="address" value="" placeholder="Адрес"
                        class="w-full p-2 mb-2 border rounded">

                    <label class="block mt-4">Телефоны:</label>
                    @foreach ($phones as $index => $phone)
                        <div class="flex space-x-2 items-center mb-2">
                            <input type="text" wire:model="phones.{{ $index }}.number"
                                placeholder="Введите номер телефона" class="w-full p-2 border rounded">
                            <label class="flex items-center">
                                <input type="checkbox" wire:model="phones.{{ $index }}.sms" class="ml-2">
                                SMS
                            </label>
                            <button type="button" wire:click="removePhone({{ $index }})"
                                class="text-red-500">
                                <i class="fas fa-minus-circle"></i>
                            </button>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addPhone" class="bg-green-500 text-white px-2 py-1 rounded">
                        <i class="fas fa-plus"></i>
                    </button>

                    <label class="block mt-4">Emails:</label>
                    @foreach ($emails as $index => $email)
                        <div class="flex space-x-2 items-center mb-2">
                            <input type="text" wire:model="emails.{{ $index }}"
                                placeholder="Введите email" class="w-full p-2 border rounded">
                            <button type="button" wire:click="removeEmail({{ $index }})"
                                class="text-red-500">
                                <i class="fas fa-minus-circle"></i>
                            </button>
                        </div>
                    @endforeach
                    <button type="button" wire:click="addEmail" class="bg-green-500 text-white px-2 py-1 rounded">
                        <i class="fas fa-plus"></i>
                    </button>

                    <label class="block mb-1">Заметки</label>
                    <input type="text" wire:model="note" value="" placeholder="Заметки"
                        class="w-full p-2 mb-2 border rounded">
                    <label class="block mb-1">Статус</label>
                    <input type="checkbox" wire:model="status"> Активный


                    <div class="mt-4 flex justify-start space-x-2">
                        <button wire:click="saveClient" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                    </div>

                    @component('components.confirmation-modal', ['showConfirmationModal' => $showConfirmationModal])
                    @endcomponent
                </div>

                <div x-show="activeTab === 2">

                    <h3 class="text-lg font-bold mb-2">Баланс</h3>
                    <p class="mb-4">
                        Текущий баланс:
                        <span class="{{ $clientBalance < 0 ? 'text-red-500' : 'text-green-500' }}">
                            {{ $clientBalance }}
                        </span>
                    </p>
                    <table class="w-full border-collapse">
                        <thead>
                            <tr>
                                <th class="border p-2">Дата</th>
                                <th class="border p-2">Тип события</th>
                                <th class="border p-2">Сумма</th>
                                <th class="border p-2">Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($transactions as $transaction)
                                @php
                                    $eventType = $transaction['event_type'];
                                    $isIncome = $eventType === 1;
                                    $isExpense = $eventType === 0;
                                    $isInventory = $eventType === 'Оприходование';
                                    $isRed = $isIncome || $isInventory;
                                @endphp
                                <tr>
                                    <td class="border p-2">{{ $transaction['transaction_date'] }}</td>
                                    <td class="border p-2">
                                        @if ($isIncome)
                                            Приход
                                        @elseif ($isExpense)
                                            Расход
                                        @elseif ($isInventory)
                                            Оприходование
                                        @else
                                            {{ $eventType }}
                                        @endif
                                    </td>
                                    <td class="border p-2 {{ $isRed ? 'text-red-500' : 'text-green-500' }}">
                                        {{ $isRed ? '-' : '' }}{{ $transaction['amount'] }}
                                    </td>
                                    <td class="border p-2">{{ $transaction['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>


                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    @push('scripts')
        @vite('resources/js/modal.js')
        @vite('resources/js/dragdroptable.js')
        @vite('resources/js/sortcols.js')
        @vite('resources/js/cogs.js')
    @endpush

    <script>
        document.addEventListener('DOMContentLoaded', () => {

            setTimeout(() => {
                Livewire.on('refreshPage', () => {
                    location.reload();
                });
            }, 2000)
        });

        function confirmDelete(clientId) {
            document.getElementById('deleteConfirmationModal').style.display = 'flex';


        }

        function cancelDelete() {
            document.getElementById('deleteConfirmationModal').style.display = 'none';
        }
    </script>
    <script>
        function resetForm() {
            @this.resetForm();
        }
    </script>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const filterButtons = document.querySelectorAll(".filter-button");
            const rows = document.querySelectorAll("#table-body > div");

            function applyFilters() {
                const clientTypeFilter = document.querySelector(
                    ".filter-button.client-type.active"
                )?.dataset.filter || "all";
                const supplierFilter = document.querySelector(
                    ".filter-button.supplier-type.active"
                )?.dataset.filter || "all";

                rows.forEach((row) => {
                    const clientType = row.getAttribute("data-client-type");
                    const isSupplier = row.getAttribute("data-is-supplier");
                    const matchesClientType =
                        clientTypeFilter === "all" || clientType === clientTypeFilter;
                    const matchesSupplierFilter =
                        supplierFilter === "all" || isSupplier === supplierFilter;


                    row.style.display =
                        matchesClientType && matchesSupplierFilter ? "grid" : "none";
                });
            }
            filterButtons.forEach((button) => {
                button.addEventListener("click", () => {
                    const group = button.classList.contains("client-type") ?
                        ".client-type" :
                        ".supplier-type";


                    document.querySelectorAll(`.filter-button${group}`).forEach((btn) => {
                        btn.classList.remove("bg-blue-500", "text-white", "active");
                        btn.classList.add("bg-gray-200");
                    });


                    button.classList.add("bg-blue-500", "text-white", "active");
                    button.classList.remove("bg-gray-200");


                    applyFilters();
                });
            });

            applyFilters();
        });
    </script>
</div>
