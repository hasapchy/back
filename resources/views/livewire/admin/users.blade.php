<!-- filepath: /d:/OSPanel/domains/rem-online/resources/views/livewire/admin/users.blade.php -->
<div>
    @section('page-title', 'Пользователи')
    <div class="container mx-auto p-4">
        @include('components.alert')

        <div class="flex items-center mb-4">
            @can('users_create')
                <button wire:click="openForm"
                    class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded mr-4">
                    <i class="fas fa-plus"></i>
                </button>
            @endcan
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full bg-white border">
                <thead>
                    <tr>
                        @foreach ($columns as $column)
                            <th class="py-2 px-4 border bg-gray-200">
                                {{ str_replace('_', ' ', $column) }}
                            </th>
                        @endforeach
                        <th class="py-2 px-4 border bg-gray-200">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr class="hover:bg-gray-100 cursor-pointer" wire:click="editUser({{ $user->id }})">
                            @foreach ($columns as $column)
                                <td class="py-2 px-4 border">
                                    @if ($column === 'is_active')
                                        {{ $user->is_active ? 'Активен' : 'Неактивен' }}
                                    @else
                                        {{ $user->$column }}
                                    @endif
                                </td>
                            @endforeach
                            <td class="py-2 px-4 border whitespace-nowrap">
                                @can('users_edit')
                                    <button wire:click.stop="editUser({{ $user->id }})"
                                        class="bg-blue-500 text-white px-3 py-1 rounded mr-2">
                                        <i class="fas fa-pencil-alt"></i>
                                    </button>
                                @endcan
                                @can('users_delete')
                                    <button onclick="confirmDeletion({{ $user->id }})"
                                        wire:click.stop="deleteUser({{ $user->id }})"
                                        class="bg-red-500 text-white px-3 py-1 rounded">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Модальное окно для создания/редактирования пользователя -->
        <div id="modalBackground"
            class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 {{ $showForm ? 'block' : 'hidden' }}"
            wire:click="closeForm">
            <div id="form" class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg p-4 overflow-y-auto"
                wire:click.stop>
                <button wire:click="closeForm"
                    class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl">
                    &times;
                </button>
                <h2 class="text-xl font-bold mb-4">{{ $userId ? 'Редактировать' : 'Создать' }} пользователя</h2>

                <input type="text" wire:model="name" placeholder="Имя" class="w-full p-2 mb-2 border rounded">

                <input type="email" wire:model="email" placeholder="Email" class="w-full p-2 mb-2 border rounded">

                <input type="password" wire:model="password" placeholder="Пароль"
                    class="w-full p-2 mb-2 border rounded">

                <input type="date" wire:model="hire_date" placeholder="Дата приема на работу"
                    class="w-full p-2 mb-2 border rounded">

                <input type="text" wire:model="position" placeholder="Должность"
                    class="w-full p-2 mb-2 border rounded">

                <!-- Блок выбора роли -->
                <div class="mb-2">
                    <label class="block mb-1">Роль</label>
                    <select wire:model="roleId" class="w-full p-2 border rounded">
                        <option value="">-- Выберите роль --</option>
                        @foreach ($availableRoles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </select>
                </div>

                <button wire:click="saveUser" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 mt-4 rounded">
                    <i class="fas fa-save"></i>
                </button>
                @can('users_delete')
                    @if ($userId)
                        <button onclick="confirmDeletion({{ $userId }})"
                            wire:click="deleteUser({{ $userId }})"
                            class="bg-red-500 text-white px-4 py-2 mt-4 rounded">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    @endif
                @endcan

                @component('components.confirmation-modal', ['showConfirmationModal' => $showConfirmationModal])
                @endcomponent
            </div>
        </div>
    </div>

    <script>
        function confirmDeletion(userId) {
            if (confirm('Вы действительно хотите удалить пользователя?')) {
                @this.call('deleteUser', userId);
            }
        }
    </script>
</div>
