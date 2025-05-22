@section('page-title', 'Управление складами')
@section('showSearch', false)
<div class="mx-auto p-4">
    <div class="flex items-center space-x-4 mb-4">
        <button wire:click="openForm" class="bg-[#5CB85C] text-white px-4 py-2 rounded mb-4">
            <i class="fas fa-plus"></i>
        </button>
        @include('components.warehouse-accordion')
    </div>
    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="py-2 px-4 border">Название</th>
                <th class="py-2 px-4 border">Дата создания</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($warehouses as $warehouse)
                <tr wire:click="edit({{ $warehouse->id }})" class="cursor-pointer">

                    <td class="py-2 px-4 border-b">{{ $warehouse->name }}</td>
                    <td class="py-2 px-4 border-b">{{ $warehouse->created_at->format('d.m.Y') }}</td>
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
            <h2 class="text-xl font-bold mb-4">Склад</h2>
            <div>
                <label>Название</label>
                <input type="text" wire:model="name" class="w-full p-2 border rounded">
            </div>

            <div class="mt-4">
                <label>Назначить пользователей</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($users as $user)
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" wire:model="selectedUsers" value="{{ (string) $user->id }}">
                            <span>{{ $user->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="mt-4 flex space-x-2">
                <button wire:click="save" class="bg-[#5CB85C] text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @if ($warehouseId)
                    <button wire:click="delete({{ $warehouseId }})" class="bg-red-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
