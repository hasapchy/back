@section('page-title', 'Услуги')
@section('showSearch', true)
<div class="container mx-auto p-4">
    @php
    $sessionCurrencyCode = session('currency', 'USD');
    $conversionService = app(\App\Services\CurrencySwitcherService::class);
    $displayRate = $conversionService->getConversionRate($sessionCurrencyCode, now());
    $selectedCurrency = $conversionService->getSelectedCurrency($sessionCurrencyCode);
@endphp
    <div class="flex items-center space-x-4 mb-4">
        @include('components.alert')
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded ">
            <i class="fas fa-plus"></i>
        </button>

        @include('components.products-accordion')
        @include('components.alert')
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">ID</th>
                <th class="p-2 border border-gray-200">Название</th>
                <th class="p-2 border border-gray-200">Розничная цена</th>
                <th class="p-2 border border-gray-200">Оптовая цена</th>
                <th class="p-2 border border-gray-200">Описание</th>
                <th class="p-2 border border-gray-200">Категория</th>
                <th class="p-2 border border-gray-200">Артикул</th>

            </tr>
        </thead>
        <tbody>
            @foreach ($products as $product)
                <tr wire:click="edit({{ $product->id }})" class="cursor-pointer mb-2 p-2 border rounded">
                    <td class="p-2 border border-gray-200">{{ $product->id }}</td>
                    <td class="p-2 border border-gray-200">{{ $product->name }}</td>
                    <td class="p-2 border border-gray-200">
                        {{ number_format(($product->prices->last()->retail_price ?? 0) * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                    </td>
                    <td class="p-2 border border-gray-200">
                        {{ number_format(($product->prices->last()->wholesale_price ?? 0) * $displayRate, 2) }} {{ $selectedCurrency->symbol }}
                    </td>
                    <td class="p-2 border border-gray-200">{{ $product->description }}</td>
                    <td class="p-2 border border-gray-200">
                        {{ $product->category->name ?? 'N/A' }}
                    </td>
                    <td class="p-2 border border-gray-200">{{ $product->sku }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">{{ $productId ? 'Редактировать' : 'Создать' }} услугу</h2>

            <div x-data="{ activeTab: 1 }">
                <ul class="flex border-b mb-4">
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 1 }"
                            @click.prevent="activeTab = 1"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Общие</a>
                    </li>
                    @if ($productId)
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t text-blue-700': activeTab === 3 }"
                                @click.prevent="activeTab = 3"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">История</a>
                        </li>
                    @endif
                </ul>

                <div x-show="activeTab === 1" class="transition-all duration-500 ease-in-out">

                    <div class="flex items-center space-x-2 mb-2">
                        <select wire:model="categoryId" class="w-full p-2 border rounded">
                            <option value="">Выберите категорию</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <button wire:click="$set('showCategoryForm', true)"
                            class="bg-blue-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Название</label>
                        <input type="text" wire:model="name" placeholder="Название"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Описание</label>
                        <input type="text" wire:model="description" placeholder="Описание"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Артикул</label>
                        <input type="text" wire:model="sku" placeholder="Артикул" class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Себестоимость</label>
                        <input type="text" wire:model="purchase_price" placeholder="Себестоимость"
                            class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-2">
                        <label class="block mb-1">Розничная цена</label>
                        <input type="text" wire:model="retail_price" placeholder="Розничная цена"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Оптовая цена</label>
                        <input type="text" wire:model="wholesale_price" placeholder="Оптовая цена"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mt-4 flex justify-start space-x-2">
                        <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                        @if ($productId)
                            <button onclick="confirmDelete({{ $productId }})"
                                class="bg-red-500 text-white px-4 py-2 rounded">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        @endif
                    </div>
                </div>
            </div>



            <div id="categoryModal"
                class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 transition-opacity duration-500 {{ $showCategoryForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}">
                <div class="bg-white w-2/3 p-6 rounded-lg shadow-lg">
                    <h2 class="text-xl font-bold mb-4">Создать категорию</h2>
                    <div>
                        <label class="block mb-1">Название категории</label>
                        <input type="text" wire:model="categoryName" placeholder="Название категории"
                            class="w-full p-2 border rounded">
                    </div>
                    <div>
                        <label class="block mb-1">Родительская категория</label>
                        <select wire:model="parentCategoryId" class="w-full p-2 border rounded">
                            <option value="">Выберите родительскую категорию</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mt-4 flex justify-end space-x-2">
                        <button wire:click="saveCategory"
                            class="bg-green-500 text-white px-4 py-2 rounded">Сохранить</button>
                        <button wire:click="$set('showCategoryForm', false)"
                            class="bg-gray-500 text-white px-4 py-2 rounded">
                            Отмена
                        </button>
                    </div>
                </div>
            </div>

            <div id="deleteConfirmationModal"
                class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 transition-opacity duration-500 opacity-0 pointer-events-none">
                <div class="bg-white w-2/3 p-6 rounded-lg shadow-lg">
                    <h2 class="text-xl font-bold mb-4">Вы уверены, что хотите удалить?</h2>
                    <p>Это действие нельзя отменить.</p>
                    <div class="mt-4 flex justify-end space-x-2">
                        <button wire:click="delete({{ $productId }})" id="confirmDeleteButton"
                            class="bg-red-500 text-white px-4 py-2 rounded">Да</button>
                        <button onclick="cancelDelete()" class="bg-gray-500 text-white px-4 py-2 rounded">Нет</button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    function confirmDelete(productId) {
        const modal = document.getElementById('deleteConfirmationModal')
        modal.classList.remove('opacity-0', 'pointer-events-none')
        modal.classList.add('opacity-100', 'pointer-events-auto')
    }

    function cancelDelete() {
        const modal = document.getElementById('deleteConfirmationModal')
        modal.classList.add('opacity-0', 'pointer-events-none')
        modal.classList.remove('opacity-100', 'pointer-events-auto')
    }

</script>
