@section('page-title', 'Статьи расходов')
<div class="container mx-auto p-4">
    @include('components.alert')
    @if (Auth::user()->hasPermission('create_expense_items'))
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded mb-4">
            <i class="fas fa-plus"></i>
        </button>
    @endif
    <div id="table-container">
        <table class="min-w-full bg-white shadow-md rounded mb-6">
            <thead class="bg-gray-100">
                <tr>
                    <th class="py-2 px-4 border border-gray-200">Название</th>
                    <th class="py-2 px-4 border border-gray-200">Тип</th>

                </tr>
            </thead>
            <tbody>
                @foreach ($categories as $category)
                    @if (Auth::user()->hasPermission('edit_expense_items'))
                        <tr wire:click="edit({{ $category->id }})"
                            class="cursor-pointer {{ $category->type == '1' ? 'bg-green-100' : 'bg-red-100' }}">
                    @endif
                    <td class="py-2 px-4 border border-gray-200">{{ $category->name }}</td>
                    <td class="py-2 px-4 border border-gray-200">{{ $category->type }}</td>

                    </tr>
                @endforeach
            </tbody>
        </table>
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

            <h2 class="text-xl font-bold mb-4">{{ $categoryId ? 'Редактировать' : 'Создать' }} категорию транзакций</h2>

            <div class="mb-2">
                <label class="block mb-1">Название</label>
                <input type="text" wire:model="name" placeholder="Название" class="w-full p-2 border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Тип</label>
                <select wire:model="type" class="w-full p-2 border rounded">
                    <option value="1">Приход</option>
                    <option value="0">Расход</option>
                </select>
            </div>

            <div class="mt-4 flex justify-start space-x-2">
                <button wire:click="submit" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i> Сохранить
                </button>

                @if (Auth::user()->hasPermission('delete_expense_items'))
                    @if ($categoryId)
                        <button wire:click="confirmDelete({{ $categoryId }})"
                            class="bg-red-500 text-white px-4 py-2 rounded">
                            Удалить
                        </button>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <div id="deleteConfirmationModal"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 transition-opacity duration-500"
        style="display: none;">
        <div class="bg-white w-2/3 p-6 rounded-lg shadow-lg">
            <h2 class="text-xl font-bold mb-4">Вы уверены, что хотите удалить?</h2>
            <p>Это действие нельзя отменить.</p>
            <div class="mt-4 flex justify-end space-x-2">
                <button wire:click="delete" class="bg-red-500 text-white px-4 py-2 rounded">Да</button>
                <button onclick="cancelDelete()" class="bg-gray-500 text-white px-4 py-2 rounded">Нет</button>
            </div>
        </div>
    </div>
</div>

<script>
    function confirmDelete(categoryId) {
        document.getElementById('deleteConfirmationModal').style.display = 'flex';
    }

    function cancelDelete() {
        document.getElementById('deleteConfirmationModal').style.display = 'none';
    }

    Livewire.on('showDeleteConfirmationModal', () => {
        document.getElementById('deleteConfirmationModal').style.display = 'flex';
    });

    Livewire.on('hideDeleteConfirmationModal', () => {
        document.getElementById('deleteConfirmationModal').style.display = 'none';
    });
</script>

@push('scripts')
    @vite('resources/js/modal.js');
    @vite('resources/js/dragdroptable.js');
    @vite('resources/js/sortcols.js');
    @vite('resources/js/cogs.js');
@endpush
