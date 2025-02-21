@section('page-title', 'Роли')

<div class="container mx-auto p-4">
    @include('components.alert')
    @if (Auth::user()->hasPermission('create_roles'))
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded mb-4">
            <i class="fas fa-plus"></i>
        </button>
    @endif

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200 ">Дата создания</th>
                <th class="p-2 border border-gray-200 ">Название роли</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($roles as $role)
                @if (Auth::user()->hasPermission('edit_roles'))
                    <tr data-role-id="{{ $role->id }}" wire:click="editRole({{ $role->id }})">
                @endif
                <td class="p-2 border border-gray-200">{{ $role->created_at }}</td>
                <td class="p-2 border border-gray-200">{{ $role->name }}</td>

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
            <h2 class="text-xl font-bold mb-4">{{ $roleId ? 'Редактировать' : 'Создать' }} роль</h2>

            <label class="block mb-1">Название роли</label>
            <input type="text" wire:model="name" placeholder="Название роли" class="w-full p-2 mb-2 border rounded">

            <label class="block mb-1">Пермишены</label>
            <div class="mb-2">
                <label class="font-bold">
                    <input type="checkbox" id="selectAll">
                    Отметить все
                </label>
            </div>
            @php
                $groupedPermissions = $permissions->groupBy(function ($item) {
                    return explode('_', $item->name)[1];
                });
            @endphp
            @foreach ($groupedPermissions as $group => $groupPermissions)
                <div class="mb-2">
                    <div class=" inline-flex items-center">
                        <label class="font-bold">
                            <input type="checkbox" class="group-toggle" data-group="{{ $group }}">
                            {{ ucfirst($group) }}
                        </label>
                        @foreach ($groupPermissions as $permission)
                            <label class="flex items-center ml-3">

                                @if (strpos($permission->name, 'view') !== false)
                                    <i class="fas fa-eye text-blue-500"></i>
                                @elseif (strpos($permission->name, 'create') !== false)
                                    <i class="fas fa-plus text-green-500"></i>
                                @elseif (strpos($permission->name, 'edit') !== false)
                                    <i class="fas fa-edit text-yellow-500"></i>
                                @elseif (strpos($permission->name, 'delete') !== false)
                                    <i class="fas fa-trash text-red-500"></i>
                                @endif
                                <input type="checkbox" wire:model="selectedPermissions" value="{{ $permission->id }}"
                                    class="form-checkbox ml-1" data-group="{{ $group }}">
                            </label>
                        @endforeach
                    </div>
                </div>
                <hr class="py-1">
            @endforeach

            <div class="mt-4 flex justify-start space-x-2">
                <button wire:click="saveRole" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
            </div>
            @component('components.confirmation-modal', ['showConfirmationModal' => $showConfirmationModal])
            @endcomponent
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", () => {
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input.form-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
                checkbox.dispatchEvent(new Event('change'));
            });
        });

        document.querySelectorAll('.group-toggle').forEach(groupToggle => {
            groupToggle.addEventListener('change', function() {
                const group = this.dataset.group;
                const checkboxes = document.querySelectorAll(
                    `input.form-checkbox[data-group="${group}"]`);
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                    checkbox.dispatchEvent(new Event('change'));
                });
            });
        });
    });
</script>

<script>
    //перезагрузка страницы
    document.addEventListener('DOMContentLoaded', () => {

        setTimeout(() => {
            Livewire.on('refreshPage', () => {
                location.reload()
            })
        }, 2000)
    })

    //закрытие модального окна
    function confirmDelete(clientId) {
        document.getElementById('deleteConfirmationModal').style.display = 'flex'
    }

    function cancelDelete() {
        document.getElementById('deleteConfirmationModal').style.display = 'none'
    }
</script>
@push('scripts')
    @vite('resources/js/modal.js')
@endpush
