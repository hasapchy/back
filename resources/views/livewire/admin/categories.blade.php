@section('page-title', 'Категории')
<div class="container mx-auto p-4">
    @include('components.alert')
    <div class="flex items-center space-x-4 mb-4">

            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </button>
  
        <button id="columnsMenuButton" class="bg-gray-500 text-white px-4 py-2 rounded">
            <i class="fa fa-cogs"></i>
        </button>
    </div>

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
                        {{ str_replace('_', ' ', $column) }}
                    </div>
                @endforeach
            </div>

            <div id="table-body">
                @foreach ($categories as $category)
       
                        <div class="grid grid-flow-col auto-cols-auto" wire:click="edit({{ $category->id }})">
               
                    @foreach ($columns as $column)
                        <div class="p-2 whitespace-nowrap" data-key="{{ $column }}">
                            @if ($column == 'parent')
                                {{ $category->parent ? $category->parent->name : 'Нет' }}
                            @else
                                {{ $category->$column }}
                            @endif
                        </div>
                    @endforeach
            </div>
            @endforeach
        </div>
    </div>
</div>

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

        <h2 class="text-xl font-bold mb-4">{{ $categoryId ? 'Редактировать' : 'Создать' }} категорию</h2>
        <div class="mb-2">
            <label class="block mb-1">Название</label>
            <input type="text" wire:model="name" placeholder="Название" class="w-full p-2 border rounded">
        </div>

        <div class="mb-2">
            <label class="block mb-1">Родительская категория</label>
            <select wire:model="parent_id" class="w-full p-2 border rounded">
                <option value="">Нет</option>
                @foreach ($allCategories as $parent)
                    @if ($parent->id != $categoryId)
                        <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                    @endif
                @endforeach
            </select>
        </div>

        <div class="mb-2">
            <label class="block mb-1">Пользователи с доступом</label>
            <div class="flex flex-wrap gap-2">
                @foreach ($allUsers as $user)
                    <label class="inline-flex items-center">
                        <input type="checkbox" wire:model="users" value="{{ $user->id }}" class="form-checkbox">
                        <span class="ml-1">{{ $user->name }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="mt-4 flex justify-start space-x-2">
            <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-save"></i>
            </button>
     
                @if ($categoryId)
                    <button onclick="confirmDelete({{ $categoryId }})"
                        class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash-alt"></i>
                    </button>
              
            @endif
            <div id="deleteConfirmationModal"
                class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 transition-opacity duration-500"
                style="display: none;">
                <div class="bg-white w-2/3 p-6 rounded-lg shadow-lg">
                    <h2 class="text-xl font-bold mb-4">Вы уверены, что хотите удалить?</h2>
                    <p>Это действие нельзя отменить.</p>
                    <div class="mt-4 flex justify-end space-x-2">
                        <button wire:click="delete({{ $categoryId }})" id="confirmDeleteButton"
                            class="bg-red-500 text-white px-4 py-2 rounded">Да</button>
                        <button onclick="cancelDelete()" class="bg-gray-500 text-white px-4 py-2 rounded">Нет</button>
                    </div>
                </div>
            </div>
        </div>

        @component('components.confirmation-modal', ['showConfirmationModal' => $showConfirmationModal])
        @endcomponent
    </div>
</div>

<script>
    function confirmDelete(categoryId) {
        document.getElementById('deleteConfirmationModal').style.display = 'flex';

    }

    function cancelDelete() {
        document.getElementById('deleteConfirmationModal').style.display = 'none';
    }
</script>

@push('scripts')
    @vite('resources/js/modal.js');
    @vite('resources/js/dragdroptable.js');
    @vite('resources/js/sortcols.js');
    @vite('resources/js/cogs.js');
@endpush
