<div class="container mx-auto p-4">
    @if (session()->has('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-2 rounded mb-4">
            {{ session('success') }}
        </div>
    @endif

    <div class="flex items-center mb-4">
        <button wire:click="openForm" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded mr-4">
            Создать роль
        </button>
    </div>

    {{-- Таблица ролей --}}
    <div class="shadow w-full rounded-md overflow-hidden mb-8">
        <div class="bg-gray-200 p-2 grid grid-cols-2">
            <div>Роль</div>
            <div>Пермишены</div>
        </div>
        @foreach ($roles as $role)
            <div class="grid grid-cols-2 p-2 border-b">
                <div>{{ $role->name }}</div>
                <div>
                    @foreach ($role->permissions as $permission)
                        <span class="inline-block bg-gray-300 rounded-full px-2 py-1 text-sm mr-1 mb-1">
                            {!! permission_icon($permission->name) !!} {{ $permission->name }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    {{-- Модальное окно для создания роли --}}
    @if ($showForm)
        <div class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50"
            wire:click="closeForm">
            <div class="bg-white p-6 rounded shadow-lg w-1/3" wire:click.stop>
                <h2 class="text-xl font-bold mb-4">Создать роль</h2>
                <input type="text" wire:model="roleName" placeholder="Название роли"
                    class="w-full p-2 border rounded mb-4">

                <div class="mb-4">
                    <h3 class="font-semibold mb-2">Выберите пермишены:</h3>
                    @php
                        // Группируем разрешения по части до первого символа "_"
                        $grouped = $permissions->groupBy(function ($perm) {
                            return explode('_', $perm->name)[0];
                        });
                    @endphp

                    @foreach ($grouped as $group => $perms)
                        <div class="mb-4 border p-2 rounded">
                            <div class="flex items-center mb-2">
                                <input type="checkbox" id="group_{{ $group }}"
                                    wire:model="groupChecks.{{ $group }}"
                                    wire:click="toggleGroup('{{ $group }}')" />
                                <label for="group_{{ $group }}" class="ml-2 font-bold">
                                    {{ __("permissions.$group.title") ?? ucfirst($group) }}
                                </label>
                            </div>
                            <div class="ml-6 flex flex-wrap gap-2">
                                @foreach ($perms as $perm)
                                    <div class="flex items-center">
                                        <input type="checkbox" wire:model="selectedPermissions"
                                            value="{{ $perm->id }}" id="perm_{{ $perm->id }}">
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
                    <button wire:click="closeForm" class="mr-4 bg-gray-500 text-white px-4 py-2 rounded">
                        Отмена
                    </button>
                    <button wire:click="save" class="bg-green-500 text-white px-4 py-2 rounded">
                        Создать
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
