<div class="container mx-auto p-4">
    @if (session()->has('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center space-x-4 mb-4">

        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>


    </div>

    {{-- Таблица ролей --}}
    <div class="shadow w-full rounded-md overflow-hidden mb-8">
        <table class="min-w-full bg-white border">
            <thead class="bg-gray-200">
                <tr>
                    <th class="py-2 px-4 border">Роль</th>
                    <th class="py-2 px-4 border">Пермишены</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr class="hover:bg-gray-100 cursor-pointer" wire:click="edit({{ $role->id }})">
                        <td class="py-2 px-4 border">{{ $role->name }}</td>
                        <td class="py-2 px-4 border">
                            @foreach ($role->permissions as $permission)
                                <span class="inline-block bg-gray-300 rounded-full px-2 py-1 text-sm mr-1 mb-1">
                                    {!! permission_icon($permission->name) !!} {{ $permission->name }}
                                </span>
                            @endforeach
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Форма создания/редактирования роли, выезжающая с правой стороны --}}
    <div id="modalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-50 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 p-6"
            wire:click.stop style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};">
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">{{ $selectedRoleId ? 'Редактировать роль' : 'Создать роль' }}</h2>
            <input type="text" wire:model="roleName" placeholder="Название роли"
                class="w-full p-2 border rounded mb-4">

            <div class="mb-4">
                <h3 class="font-semibold mb-2">Выберите пермишены:</h3>
                @php
                    // Группируем разрешения по префиксу до символа "_"
                    $grouped = $permissions->groupBy(function ($perm) {
                        return explode('_', $perm->name)[0];
                    });
                @endphp

                @foreach ($grouped as $group => $perms)
                    <div class="mb-4 border p-2 rounded">
                        <div class="flex items-center mb-2">
                            <input type="checkbox" id="group_{{ $group }}"
                                wire:model="groupChecks.{{ $group }}"
                                wire:click="toggleGroup('{{ $group }}')">
                            <label for="group_{{ $group }}" class="ml-2 font-bold">
                                {{ __("permissions.$group.title") ?? ucfirst($group) }}
                            </label>
                        </div>
                        <div class="ml-6 flex flex-wrap gap-2">
                            @foreach ($perms as $perm)
                                <div class="flex items-center">
                                    <input type="checkbox" wire:model="selectedPermissions" value="{{ $perm->id }}"
                                        id="perm_{{ $perm->id }}">
                                    <label for="perm_{{ $perm->id }}" class="ml-2 flex items-center">
                                        {!! permission_icon($perm->name) !!}
                                        <span class="ml-1">
                                            {{ __('permissions.' . explode('_', $perm->name)[1]) ?? $perm->name }}
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end">

                @if ($selectedRoleId)
                    <button wire:click="delete({{ $selectedRoleId }})"
                        class="mr-4 bg-red-500 text-white px-4 py-2 rounded"
                        onclick="return confirm('Вы действительно хотите удалить эту роль?')">
                        Удалить
                    </button>
                @endif
                <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">Сохранить</button>
            </div>
        </div>
    </div>
</div>
