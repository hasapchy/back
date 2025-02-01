@section('page-title', 'Категории заказов')
<div class="mx-auto p-4 container">

    @include('components.alert')
    <div class="flex space-x-4 mb-4">
        {{-- @if (Auth::user()->hasPermission('create_order_categories')) --}}
        <button wire:click="openForm" class="mb-4 bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
        {{-- @endif --}}
    </div>
    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">Дата создания</th>
                <th class="p-2 border border-gray-200">Название</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($categories as $category)
                {{-- @if (Auth::user()->hasPermission('edit_order_categories')) --}}
                <tr wire:click="edit({{ $category->id }})"
                    class="cursor-pointer mb-2 p-2 border rounded {{ $category_id == $category->id ? 'bg-gray-200' : '' }}">
                    {{-- @endif --}}
                    <td class="p-2 border border-gray-200">{{ $category->created_at}}</td>
                    <td class="p-2 border border-gray-200">{{ $category->name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Категория заказа</h2>
            @include('components.confirmation-modal')
            <div class="mb-4">
                <label class="block mb-1">Название</label>
                <input type="text" wire:model="name" placeholder="Название" class="w-full p-2 border rounded">
            </div>

            <div class="flex space-x-2">
                <button wire:click="store" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if (Auth::user()->hasPermission('delete_order_categories'))
                    @if ($category_id)
                        <button wire:click="deleteCategoryForm({{ $category_id }})"
                            class="bg-red-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-trash"></i>
                        </button>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmationModal"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-50 transition-opacity duration-500 {{ $showConfirmationModal ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeConfirmationModal">
        <div class="fixed top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-white p-6 rounded shadow-lg"
            wire:click.stop>
            <h3 class="text-lg font-bold mb-4">Подтверждение удаления</h3>
            <p>Вы уверены, что хотите удалить эту категорию заказа?</p>
            <div class="flex justify-end space-x-4 mt-4">
                <button wire:click="closeConfirmationModal" class="bg-gray-500 text-white px-4 py-2 rounded">
                    Отмена
                </button>
                <button wire:click="confirmDelete" class="bg-red-500 text-white px-4 py-2 rounded">
                    Удалить
                </button>
            </div>
        </div>
    </div>
</div>
