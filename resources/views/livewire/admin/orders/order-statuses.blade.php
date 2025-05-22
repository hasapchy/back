@section('page-title', 'Статусы заказов')
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
                <th class="p-1 border border-gray-200">Название</th>
                <th class="p-1 border border-gray-200">Категория статуса</th>

            </tr>
        </thead>
        <tbody>
            @foreach ($statuses as $status)
                <tr wire:click="edit({{ $status->id }})" class="cursor-pointer mb-2 p-2 border rounded">

                    <td class="p-1 border border-gray-200">{{ $status->name }}</td>
                    <td class="p-1 border border-gray-200">{{ $status->category->name }}</td>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Статус заказа</h2>

            <div class="mb-4">
                <label class="block mb-1">Название</label>
                <input type="text" wire:model="name" placeholder="Название" class="w-full p-2 border rounded">
            </div>

            <div class="mb-4">
                <label class="block mb-1">Категория Статуса</label>
                <select wire:model="categoryId" class="w-full p-2 border rounded">
                    <option value="">Выберите категорию статуса</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex space-x-2">
                <button wire:click="save" class="bg-[#5CB85C] text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if ($statusId)
                    <button wire:click="delete({{ $statusId }})" class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
