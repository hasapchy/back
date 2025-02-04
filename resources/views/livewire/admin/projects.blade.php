@section('page-title', 'Управление проектами')
@section('showSearch', true)
<div class="mx-auto p-4 container">
    @include('components.alert')


    <div class="flex items-center space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_projects'))
            <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </button>
        @endif
        @livewire('admin.date-filter')
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="p-2 border border-gray-200">Название</th>
                <th class="p-2 border border-gray-200">Клиент</th>
                <th class="p-2 border border-gray-200">Дата начала</th>
                <th class="p-2 border border-gray-200">Дата конца</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($projects as $project)
                @if (Auth::user()->hasPermission('edit_projects'))
                    <tr wire:click="selectProject({{ $project->id }})"
                        class="cursor-pointer mb-2 p-2 border rounded {{ $projectId == $project->id ? 'bg-gray-200' : '' }}">
                @endif
                <td class="p-2 border border-gray-200">{{ $project->name }}</td>
                <td class="p-2 border border-gray-200">{{ $project->client->first_name ?? 'N/A' }}</td> <!-- Update this line -->
                <td class="p-2 border border-gray-200">{{ $project->start_date }}</td>
                <td class="p-2 border border-gray-200">{{ $project->end_date }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>



    <div id="modalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="form" x-data="{ activeTab: 1 }"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
                style="right: 1rem;">
                &times;
            </button>
            <h2 class="text-xl font-bold mb-4">Проект</h2>
            @include('components.confirmation-modal')

            <div x-data="{ activeTab: 1 }">
                <ul class="flex border-b mb-4">
                    <li class="-mb-px mr-1">
                        <a :class="{ 'border-l border-t border-r rounded-t-lg text-blue-700': activeTab === 1 }"
                            @click.prevent="activeTab = 1"
                            class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                            href="#">Общие</a>
                    </li>
                    @if ($projectId)
                        <li class="-mb-px mr-1">
                            <a :class="{ 'border-l border-t border-r rounded-t-lg text-blue-700': activeTab === 2 }"
                                @click.prevent="activeTab = 2"
                                class="bg-white inline-block py-2 px-4 text-blue-500 hover:text-blue-800 font-semibold"
                                href="#">Баланс</a>
                        </li>
                    @endif
                </ul>
                <div x-show="activeTab === 1">
                    <div class="mb-4">
                        <label class="block mb-1">Название</label>
                        <input type="text" wire:model="name" placeholder="Название"
                            class="w-full p-2 border rounded">
                    </div>
                    <div class="mb-4">
                        @include('components.client-search')
                    </div>
                    <div class="mb-4">
                        <label class="block mb-1">Дата начала</label>
                        <input type="date" wire:model="start_date" class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-4">
                        <label class="block mb-1">Дата конца</label>
                        <input type="date" wire:model="end_date" class="w-full p-2 border rounded">
                    </div>

                    <div class="mb-4">
                        <label class="block mb-1">Пользователи</label>
                        @foreach ($allUsers as $user)
                            <div class="flex items-center mb-2">
                                <input type="checkbox" wire:model="users" value="{{ $user->id }}" class="mr-2">
                                <label>{{ $user->name }}</label>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex space-x-2">
                        <button wire:click="saveProject" class="bg-green-500 text-white px-4 py-2 rounded">
                            <i class="fas fa-save"></i>
                        </button>
                        @if (Auth::user()->hasPermission('delete_projects'))
                            @if ($projectId)
                                <button wire:click="confirmDeleteProject({{ $projectId }})"
                                    class="bg-red-500 text-white px-4 py-2 rounded">
                                    <i class="fas fa-trash"></i>
                                </button>
                            @endif
                        @endif
                    </div>
                </div>

                <div x-show="activeTab === 2">
                    <h3 class="text-lg font-bold mb-4">Транзакции</h3>
                    <div class="mb-4">
                        <strong>Итоговая сумма: </strong>
                        <span
                            class="{{ $totalAmount >= 0 ? 'text-green-500' : 'text-red-500' }}">{{ $totalAmount }}</span>
                    </div>
                    <table class="min-w-full bg-white shadow-md rounded mb-6">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-2 border border-gray-200">Тип</th>
                                <th class="p-2 border border-gray-200">Дата</th>
                                <th class="p-2 border border-gray-200">Сумма</th>
                                <th class="p-2 border border-gray-200">Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($projectTransactions as $transaction)
                                <tr>
                                    <td
                                        class="p-2 border border-gray-200 {{ $transaction->type == 1 ? 'bg-green-200' : 'bg-red-200' }}">
                                        {{ $transaction->type == 1 ? 'Приход' : 'Расход' }}
                                    </td>
                                    <td class="p-2 border border-gray-200">{{ $transaction->transaction_date }}
                                    </td>
                                    <td
                                        class="p-2 border border-gray-200 {{ $transaction->type == 1 ? 'text-green-500' : 'text-red-500' }}">
                                        {{ $transaction->amount }}
                                    </td>
                                    <td class="p-2 border border-gray-200">{{ $transaction->note }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- @if ($showDeleteConfirmationModal)
        <div class="fixed inset-0 bg-gray-900 bg-opacity-50 з-40 flex items-center justify-center">
            <div class="bg-white p-4 rounded shadow-lg">
                <h2 class="text-xl font-bold mb-4">Подтверждение удаления</h2>
                <p>Вы уверены, что хотите удалить этот проект? Это действие нельзя отменить.</p>
                <div class="flex space-x-2 mt-4">
                    <button wire:click="deleteProject({{ $projectId }})"
                        class="bg-red-500 text-white px-4 py-2 rounded">Удалить</button>
                    <button wire:click="$set('showDeleteConfirmationModal', false)"
                        class="bg-gray-500 text-white px-4 py-2 rounded">Отмена</button>
                </div>
            </div>
        </div>
    @endif --}}
    </div>
