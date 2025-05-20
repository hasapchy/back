@section('page-title', 'Продажи')
@section('showSearch', true)
<div class="mx-auto p-4">
    @include('components.alert')

    @php
        $sessionCurrencyCode = session('currency', 'USD');
        $conversionService = app(\App\Services\CurrencySwitcherService::class);
        $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
        $selectedCurrency = $conversionService->getSelectedCurrency($sessionCurrencyCode);
    @endphp

    <div class="flex justify-between items-center mb-4">
        <div class="flex items-center space-x-4">
            <button wire:click="openForm(false)" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </button>
            <!-- Фильтр по дате -->
            <div class="relative">
                <select wire:model.live="dateFilter" class="border rounded px-2 py-1">
                    <option value="today">Сегодня</option>
                    <option value="this_week">Эта неделя</option>
                    <option value="this_month">Этот месяц</option>
                    <option value="this_year">Этот год</option>
                    <option value="yesterday">Вчера</option>
                    <option value="last_week">Прошлая неделя</option>
                    <option value="last_month">Прошлый месяц</option>
                    <option value="last_year">Прошлый год</option>
                    <option value="custom">Кастомный диапазон</option>
                </select>
            </div>
            <!-- Поля для кастомного диапазона -->
            <div x-show="$wire.dateFilter === 'custom'" class="flex space-x-2">
                <input type="date" wire:model.live="customDateRange.start" class="border rounded px-2 py-1">
                <input type="date" wire:model.live="customDateRange.end" class="border rounded px-2 py-1">
            </div>
        </div>
        <div class="flex items-center space-x-2">
            <div x-data="{ open: false, visibility: @js($visibility) }" class="relative">
                <button @click="open = !open" class="px-4 py-2 rounded">
                    <i class="fas fa-cog"></i>
                </button>
                <div x-show="open" @click.away="open = false"
                    class="absolute right-0 mt-2 z-50 bg-white p-4 shadow-md rounded border" style="min-width: 200px;">
                    @foreach ($columns as $column)
                        <label class="block">
                            <input type="checkbox" x-model="visibility['{{ $column['key'] }}']"
                                @change="$wire.updateTableSettings(@js($order), visibility)">
                            {{ $column['title'] }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div>
                <select wire:model.live="perPage" class="appearance-none bg-none border rounded px-2 py-1">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Total and Delete Button Above Table -->
    <div class="mb-4" x-show="$wire.selectedSaleIds.length > 0">
        <div class="flex justify-between items-center">
            <div>
                <strong>Общая сумма выбранных продаж:</strong>
                <span wire:model.live="selectedTotal">
                    {{ number_format($selectedTotal * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                </span>
            </div>
            <button wire:click="deleteSelected" wire:confirm="Вы уверены, что хотите удалить выбранные продажи?"
                class="bg-red-500 text-white px-4 py-2 rounded" :disabled="!$wire.selectedSaleIds.length">
                <i class="fas fa-trash"></i> Удалить выбранные
            </button>
        </div>
    </div>

    <!-- Table -->
    <table class="min-w-full bg-white shadow-md rounded mb-6" x-data="tableSettings()">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">
                    <input type="checkbox" wire:model.live="selectAll">
                </th>
                @foreach ($order as $columnKey)
                    @if ($visibility[$columnKey] ?? true)
                        <th class="p-2 border border-gray-200" data-column="{{ $columnKey }}">
                            {{ $columns->firstWhere('key', $columnKey)['title'] }}
                        </th>
                    @endif
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($salesData as $sale)
                <tr class="hover:bg-gray-100 cursor-pointer">
                    <td class="p-2 border border-gray-200">
                        <input type="checkbox" wire:model.live="selectedSaleIds" value="{{ $sale->id }}"
                            x-on:change="$wire.updateSelectedTotal()">
                    </td>
                    @foreach ($order as $columnKey)
                        @if ($visibility[$columnKey] ?? true)
                            <td class="p-2 border border-gray-200" wire:click="edit({{ $sale->id }})">
                                @if ($columnKey === 'id')
                                    {{ $sale->id }}
                                @elseif ($columnKey === 'date')
                                    {{ \Carbon\Carbon::parse($sale->date)->format('d.m.Y') }}
                                @elseif ($columnKey === 'client.first_name')
                                    {{ $sale->client->first_name ?? '-' }}
                                @elseif ($columnKey === 'warehouse.name')
                                    {{ $sale->warehouse->name ?? '-' }}
                                @elseif ($columnKey === 'products')
                                    @foreach ($sale->products as $product)
                                        <div>{{ $product->name }}: {{ $product->pivot->quantity }}шт</div>
                                    @endforeach
                                @elseif ($columnKey === 'total_price')
                                    {{ number_format($sale->total_price * $displayRate, 2) }}
                                    {{ $selectedCurrency->symbol }}
                                @elseif ($columnKey === 'note')
                                    {{ $sale->note ?? '-' }}
                                @else
                                    @php
                                        $keys = explode('.', $columnKey);
                                        $value = $sale;
                                        foreach ($keys as $key) {
                                            $value = $value->$key ?? '-';
                                        }
                                        echo $value;
                                    @endphp
                                @endif
                            </td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Total Below Table -->
    <div class="mt-4" x-show="$wire.selectedSaleIds.length > 0">
        <strong>Общая сумма выбранных продаж:</strong>
        <span wire:model.live="selectedTotal">
            {{ number_format($selectedTotal * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
        </span>
    </div>

    <div class="mt-4">
        {{ $salesData->links() }}
    </div>

    <!-- Форма создания/редактирования продажи -->
    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500
             {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}">
        <div id="form"
            class="fixed top-0 overflow-y-auto right-0 w-1/3 h-full bg-white shadow-lg
             transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
            wire:click.stop>
            <button wire:click="closeForm"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl">×</button>
            <h2 class="text-xl font-bold mb-4">
                {{ $saleId ? 'Редактировать продажу' : 'Добавить продажу' }}
            </h2>
            <form wire:submit.prevent="save">
                <div class="mb-4">
                    <label>Дата</label>
                    <input type="date" wire:model="date" class="w-full border rounded"
                        @if ($saleId) disabled @endif>
                </div>
                <div class="mb-4">
                    <label for="warehouse" class="block">Склад</label>
                    <select id="warehouse" wire:model="warehouseId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId || count($selectedProducts) > 0) disabled @endif>
                        <option value="">Выберите склад</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if (!$saleId)
                    @include('components.client-search')
                    @include('components.product-search')
                @endif
                <div class="mb-4">
                    <label class="block">Тип оплаты</label>
                    <div class="mt-1 flex items-center">
                        <label class="mr-4">
                            <input type="radio" wire:model.change="paymentType" value="0" class="mr-1"
                                @if ($saleId) disabled @endif>
                            С баланса
                        </label>
                        <label>
                            <input type="radio" wire:model.change="paymentType" value="1" class="mr-1"
                                @if ($saleId) disabled @endif>
                            С кассы
                        </label>
                    </div>
                </div>
                <div class="mb-4" @if ($paymentType != 1) style="display: none;" @endif>
                    <label for="cash_register" class="block">Касса</label>
                    <select id="cash_register" wire:model="cashId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите кассу</option>
                        @foreach ($cashRegisters as $cashRegister)
                            <option value="{{ $cashRegister->id }}">
                                {{ $cashRegister->name }}
                                ({{ optional($currencies->firstWhere('id', $cashRegister->currency_id))->symbol }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label for="projectId" class="block">Проект</label>
                    <select id="projectId" wire:model="projectId"
                        class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif>
                        <option value="">Выберите проект</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-4">
                    <label for="note" class="block">Примечание</label>
                    <textarea id="note" wire:model="note" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm"
                        @if ($saleId) disabled @endif></textarea>
                </div>
                <h3 class="text-lg font-bold mb-4">Выбранные товары</h3>
                <table class="w-full border-collapse border border-gray-200 shadow-md rounded">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 border border-gray-200">Товар</th>
                            <th class="p-2 border border-gray-200">Количество</th>
                            <th class="p-2 border border-gray-200">Цена</th>
                            <th class="p-2 border border-gray-200">Действия</th>
                        </tr>
                    </thead>
                    @if ($selectedProducts)
                        <tbody>
                            @foreach ($selectedProducts as $productId => $details)
                                <tr>
                                    <td class="p-2 border border-gray-200">
                                        <div class="flex items-center">
                                            @if (!$details['image'])
                                                <img src="{{ asset('no-photo.jpeg') }}"
                                                    class="w-16 h-16 object-cover">
                                            @else
                                                <img src="{{ Storage::url($details['image']) }}"
                                                    class="w-16 h-16 object-cover">
                                            @endif
                                            <span class="ml-2">{{ $details['name'] }}</span>
                                        </div>
                                    </td>
                                    <td class="p-2 border border-gray-200">
                                        <input type="number"
                                            wire:model.live="selectedProducts.{{ $productId }}.quantity"
                                            class="w-full border rounded" min="1">
                                    </td>
                                    <td class="p-2 border border-gray-200">
                                        <input type="number"
                                            wire:model.live="selectedProducts.{{ $productId }}.price"
                                            class="w-full border rounded" step="0.01" min="0.01">
                                    </td>
                                    <td class="p-2 border border-gray-200">
                                        <button type="button" wire:click="removeProduct({{ $productId }})"
                                            class="text-red-500">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        @php
                            if ($totalDiscountType === 'fixed') {
                                $discountValue = $totalDiscount / $displayRate;
                            } else {
                                $discountValue = $totalPrice * ($totalDiscount / 100);
                            }
                            $finalTotal = $totalPrice - $discountValue;
                        @endphp
                        <tfoot class="bg-gray-100">
                            <tr>
                                <td class="p-2 border border-gray-200 font-bold" colspan="2">Всего:</td>
                                <td class="p-2 border border-gray-200 font-bold">
                                    {{ number_format($totalPrice * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                                </td>
                                <td class="p-2 border border-gray-200"></td>
                            </tr>
                            <tr>
                                <td class="p-2 border border-gray-200 font-bold" colspan="2">
                                    <div class="flex items-center space-x-2">
                                        <span>Скидка:</span>
                                        <select wire:model.live="totalDiscountType" class="border rounded">
                                            <option value="fixed">Фиксированная</option>
                                            <option value="percent">Процентная</option>
                                        </select>
                                    </div>
                                </td>
                                <td class="p-2 border border-gray-200" colspan="2">
                                    <input type="number" step="0.01" wire:model.live="totalDiscount"
                                        class="w-full border rounded" placeholder="Значение скидки">
                                </td>
                            </tr>
                            <tr>
                                <td class="p-2 border border-gray-200 font-bold" colspan="2">Итоговая цена:</td>
                                <td class="p-2 border border-gray-200 font-bold">
                                    {{ number_format($finalTotal * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                                </td>
                                <td class="p-2 border border-gray-200"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
                <div class="flex justify-start mt-4">
                    <button type="submit" class="bg-green-500 text-white px-4 py-2 rounded mr-2"
                        @if ($saleId) disabled @endif>
                        <i class="fas fa-save"></i>
                    </button>
                    @if ($saleId)
                        <button type="button" wire:click="delete" class="bg-red-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-trash"></i>
                        </button>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <script>
        function tableSettings() {
            return {
                init() {
                    const tableHead = this.$el.querySelector('thead tr');
                    if (!tableHead) {
                        console.error('Table head row not found');
                        return;
                    }
                    Sortable.create(tableHead, {
                        animation: 150,
                        handle: 'th[data-column]', // Only allow sorting on th with data-column
                        onEnd: (evt) => {
                            const visibleOrder = Array.from(tableHead.querySelectorAll('th[data-column]'))
                                .map(th => th.dataset.column)
                                .filter(column => column);
                            const allColumns = @js($columns->pluck('key')->toArray());
                            const hiddenColumns = allColumns.filter(col => !visibleOrder.includes(col));
                            const newOrder = [...visibleOrder, ...hiddenColumns];
                            console.log('New order:', newOrder);
                            this.updateSettings(newOrder);
                        }
                    });
                },
                updateSettings(newOrder) {
                    @this.updateTableSettings(newOrder, @js($visibility));
                }
            }
        }
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const backdrop = document.getElementById('modalBackground');
            let downOnBackdrop = false;

            backdrop.addEventListener('mousedown', e => {
                if (e.target === backdrop) downOnBackdrop = true;
            });

            document.addEventListener('mouseup', e => {
                if (downOnBackdrop && e.target === backdrop) {
                    Livewire.find(backdrop.closest('[wire\\:id]').getAttribute('wire:id'))
                        .call('closeForm');
                }
                downOnBackdrop = false;
            });
        });
    </script>

  
        <script>
            document.addEventListener('livewire:init', () => {
                Livewire.on('update-checkboxes', () => {
                    // Форсируем обновление Livewire для синхронизации чекбоксов
                    Livewire.dispatch('refresh');
                });
            });
        </script>
  

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
</div>
