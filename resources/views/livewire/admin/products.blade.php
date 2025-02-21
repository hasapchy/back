@section('page-title', 'Управление товарами')
@section('showSearch', true)
<div class="container mx-auto p-4">

    <div class="flex items-center space-x-4 mb-4">
        @include('components.alert')
        @if (Auth::user()->hasPermission('create_products'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded ">
                <i class="fas fa-plus"></i>
            </button>
        @endif
        <button id="columnsMenuButton" class="bg-gray-500 text-white px-4 py-2 rounded">
            <i class="fas fa-cogs"></i>
        </button>
        @include('components.products-accordion')
        @include('components.alert')
    </div>
    <!-- Меню фильтров -->
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
            <div id="header-row" class="grid grid-flow-col auto-cols-auto">
                @foreach ($columns as $column)
                    <div class="p-2 cursor-move whitespace-nowrap" data-key="{{ $column }}">
                        {{ $column === 'thumbnail' ? 'Изобр.' : str_replace('_', ' ', $column) }}
                    </div>
                @endforeach
            </div>

            <div id="table-body">
                @foreach ($products as $product)
                    <div class="grid grid-flow-col auto-cols-auto" wire:click="edit({{ $product->id }})">
                        @foreach ($columns as $column)
                            <div class="p-2 whitespace-nowrap" data-key="{{ $column }}">
                                @if ($column === 'thumbnail')
                                    @if ($product->image)
                                        <img src="{{ asset('storage/' . $product->image) }}" alt="Thumbnail"
                                            class="w-12 h-12 object-cover">
                                    @else
                                        <img src="{{ asset('no-photo.jpeg') }}" class="w-16 h-16 object-cover">
                                    @endif
                                @else
                                    {{ $column === 'stock_quantity' ? $product->stocks->sum('quantity') : $product->$column }}
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div id="modalBackground"
        class="fixed  inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed overflow-y-auto top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">{{ $productId ? 'Редактировать' : 'Создать' }} товар</h2>

            <div x-data="{ activeTab: 1 }">
                <ul class="flex border-b mb-4">
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t-lg text-blue-700': activeTab === 1 }"
                            @click.prevent="activeTab = 1"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Общие</a>
                    </li>
                    @if ($productId)
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t-lg text-blue-700': activeTab === 2 }"
                                @click.prevent="activeTab = 2"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">Остатки</a>
                        </li>
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t-lg text-blue-700': activeTab === 3 }"
                                @click.prevent="activeTab = 3"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">История</a>
                        </li>
                    @endif
                </ul>

                <div x-show="activeTab === 1" class="transition-all duration-500 ease-in-out">
                    <div class="mb-4 " style="width: 50%; height:50%">
                        <label class="block mb-1">Фотография</label>
                        @if ($image)
                            <div class="relative inline-block">
                                @if ($image instanceof Livewire\TemporaryUploadedFile)
                                    <img src="{{ $image->temporaryUrl() }}" class="w-100 h-100 object-cover">
                                @else
                                    <img src="{{ asset('storage/' . $image) }}" class="w-100 h-100 object-cover">
                                @endif
                                <span class="absolute top-0 right-0 bg-red-500 text-white cursor-pointer px-2"
                                    wire:click="removeImage">X</span>
                            </div>
                        @else
                            <div class="flex items-center space-x-2">
                                <img src="{{ asset('no-photo.jpeg') }}" class="w-16 h-16 object-cover">
                                <div x-data="{ progress: 0, hasFile: false }" x-on:change="hasFile = true"
                                    x-on:livewire-upload-start="progress = 0"
                                    x-on:livewire-upload-finish="progress = 100"
                                    x-on:livewire-upload-error="progress = 0"
                                    x-on:livewire-upload-progress="progress = $event.detail.progress" class="w-full">
                                    <input type="file" wire:model="image" class="p-2 border rounded">
                                    <div x-show="hasFile" class="mt-2">
                                        <progress max="100" class="w-full" x-bind:value="progress"></progress>
                                        <span x-text="progress + '%'"></span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

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
                        <input type="text" wire:model="sku" placeholder="Артикул"
                            class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-2">
                        <label class="block mb-1">Валюта</label>
                        <select wire:model="currencyId" class="w-full p-2 border rounded">
                            <option value="">Выберите валюту</option>
                            @foreach ($currencies as $currency)
                                <option value="{{ $currency->id }}">{{ $currency->name }}</option>
                            @endforeach
                        </select>
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

                    <div class="mb-2">
                        <label class="block mb-1">Штрих-код (EAN-13)</label>
                        <div class="flex items-center gap-2">
                            <input type="text" wire:model="barcode" readonly
                                class="w-full p-2 border rounded bg-gray-100">
                            @if (!$barcode)
                                <button wire:click="generateBarcode" class="bg-blue-500 text-white px-4 py-2 rounded">
                                    <i class="fas fa-barcode"></i>
                                </button>
                            @endif
                        </div>

                    </div>

                    <div class="mt-4 flex justify-start space-x-2">
                        <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                        @if ($productId && auth()->user()->hasPermission('view_clients'))
                            <button onclick="confirmDelete({{ $productId }})"
                                class="bg-red-500 text-white px-4 py-2 rounded">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        @endif
                    </div>
                </div>

                <div x-show="activeTab === 2">
                    <table class="min-w-full bg-white shadow-md rounded mb-6">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border p-2">Склад</th>
                                <th class="border p-2">Количество</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stocks as $stock)
                                <tr>
                                    <td class="border p-2">{{ $stock->warehouse->name }}</td>
                                    <td class="border p-2">{{ $stock->quantity }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div x-show="activeTab === 3" class="transition-all duration-500 ease-in-out">
                    <table class="min-w-full bg-white shadow-md rounded mb-6">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border p-2">Тип</th>
                                <th class="border p-2">Дата</th>
                                <th class="border p-2">Количество</th>
                                <th class="border p-2">Склад</th>
                                <th class="border p-2">Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $entry)
                                <tr>
                                    <td class="border p-2">{{ $entry['type'] }}</td>
                                    <td class="border p-2">{{ $entry['date'] }}</td>
                                    <td class="border p-2">
                                        @if ($entry['type'] === 'Оприходование')
                                            <span class="text-green-500">+{{ $entry['quantity'] }}</span>
                                        @elseif ($entry['type'] === 'Продажа' || $entry['type'] === 'Списание')
                                            <span class="text-red-500">-{{ $entry['quantity'] }}</span>
                                        @else
                                            {{ $entry['quantity'] }}
                                        @endif
                                    </td>
                                    <td class="border p-2">{{ $entry['warehouse'] ?? '' }}</td>
                                    <td class="border p-2">{{ $entry['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            @component('components.confirmation-modal', ['showConfirmationModal' => $showConfirmationModal])
            @endcomponent

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
                    <div class="mb-2">
                        <label class="block mb-1">Пользователи с доступом</label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($allUsers as $user)
                                <label class="inline-flex items-center">
                                    <input type="checkbox" wire:model="users" value="{{ $user->id }}"
                                        class="form-checkbox">
                                    <span class="ml-1">{{ $user->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-4 flex space-x-2">
                        <button wire:click="saveCategory" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                        <button wire:click="$set('showCategoryForm', false)"
                            class="bg-gray-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-times"></i>
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

    // document.querySelector('input[wire\\:click="confirmSerialization"]').addEventListener('click', function(event) {
    //     event.preventDefault();
    //     @this.call('confirmSerialization');
    // });
</script>

@push('scripts')
    @vite('resources/js/dragdroptable.js')
    @vite('resources/js/sortcols.js') @vite('resources/js/cogs.js')
@endpush
