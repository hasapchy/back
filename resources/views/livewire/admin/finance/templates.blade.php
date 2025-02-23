@section('page-title', 'Шаблоны')
<div class="mx-auto p-4 container">
    @include('components.alert')

    <div class="flex items-center space-x-4 mb-4">
      
        <button wire:click="openForm" class="bg-green-500 text-white px-4 py-2 rounded">
            <i class="fas fa-plus"></i>
        </button>
    
        @include('components.finance-accordion')
    </div>
    <div class="flex flex-wrap">
        @foreach ($templates as $template)
            <div class="w-1/6">
                <div class="border rounded px-2 py-1 flex flex-col items-center">
                    <i class="{{ $template->icon }} text-3xl mb-2"></i>
                    <span class="font-semibold">{{ $template->name }}</span>
                    <div class="mt-2 flex space-x-2">
                        <button wire:click="applyTemplate({{ $template->id }})"
                            class="bg-green-500 text-white px-2 py-1 rounded">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button wire:click="edit({{ $template->id }})"
                            class="bg-yellow-500 text-white px-2 py-1 rounded ">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button wire:click="delete({{ $template->id }})"
                            class="bg-red-500 text-white px-2 py-1 rounded ">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div id="templateModalBackground"
        class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeForm">
        <div id="templateForm"
            class="fixed top-0 overflow-y-auto right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
            style="transform: {{ $showForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeForm"
                class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl">
                &times;
            </button>

            <h2 class="text-xl font-bold mb-4">{{ $templateId ? 'Редактировать шаблон' : 'Создать шаблон' }}</h2>

            <form>
                <div class="mb-2">
                    <label class="block mb-1">Название</label>
                    <input type="text" wire:model="templateName" placeholder="Название шаблона"
                        class="w-full p-2 border rounded">
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Иконка</label>
                    <select wire:model="templateIcon" class="w-full p-2 border rounded">
                        <option value="">Выберите иконку</option>
                        <option value="fas fa-shopping-cart">Shopping Cart</option>
                        <option value="fas fa-money-bill">Money Bill</option>
                        <option value="fas fa-credit-card">Credit Card</option>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Сумма</label>
                    <input type="text" wire:model="templateAmount" placeholder="Сумма"
                        class="w-full p-2 border rounded">
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Тип операции</label>
                    <select wire:model.live="type" class="w-full p-2 border rounded">
                        <option value="1">Приход</option>
                        <option value="0">Расход</option>
                    </select>
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Категория</label>
                    <select wire:model="categoryId" class="w-full p-2 border rounded">
                        <option value="">Выберите категорию</option>
                        @foreach ($filteredCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Валюта</label>
                    <select wire:model="currency_id" class="w-full p-2 border rounded">
                        <option value="">Выберите валюту</option>
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->id }}">{{ $currency->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Дата операции</label>
                    <input type="date" wire:model="templateTransactionDate" class="w-full p-2 border rounded">
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Примечание</label>
                    <textarea wire:model.blur="templateNote" placeholder="Примечание" class="w-full p-2 border rounded"
                        wire:dirty.class="border-yellow-500"></textarea>
                    <div wire:dirty wire:target="templateNote">Unsaved title...</div>
                </div>

                <div class="mb-2">
                    @include('components.client-search')
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Касса</label>
                    <select wire:model="cashId" class="w-full p-2 border rounded">
                        <option value="">Выберите кассу</option>
                        @foreach ($cashRegisters as $register)
                            <option value="{{ $register->id }}">{{ $register->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-2">
                    <label class="block mb-1">Проект</label>
                    <select wire:model="projectId" class="w-full p-2 border rounded">
                        <option value="">Выберите проект</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}">{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mt-4 flex justify-start space-x-2">
                    <button wire:click="saveTemplate" class="bg-green-500 text-white px-4 py-2 rounded">
                        <i class="fas fa-save"></i>
                    </button>
                </div>
            </form>

            @include('components.confirmation-modal')
        </div>
    </div>

    <div id="applyTemplateModalBackground"
        class="fixed inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showTForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
        wire:click="closeTForm">
        <div id="applyTemplateForm"
            class="fixed top-0 right-0 w-1/3 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto px-2 py-1"
            style="transform: {{ $showTForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
            <button wire:click="closeTForm"
                class="absolute topx-2 py-1 right-4 text-gray-500 hover:text-gray-700 text-2xl" style="right: 1rem;">
                &times;
            </button>
            @include('components.confirmation-modal')
            <h2 class="text-xl font-bold mb-4">Применить шаблон</h2>

            <div class="mb-2">
                <label class="block mb-1">Название</label>
                <input type="text" wire:model="templateName" placeholder="Название шаблона"
                    class="w-full p-2 border rounded" disabled>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Иконка</label>
                <select wire:model="templateIcon" class="w-full p-2 border rounded" disabled>
                    <option value="">Выберите иконку</option>
                    <option value="fas fa-shopping-cart">
                        shopping-cart
                    </option>
                    <option value="fas fa-money-bill">
                        <i class="fas fa-money-bill">fa-money-bill</i>
                    </option>
                    <option value="fas fa-credit-card">
                        <i class="fas fa-credit-card">fa-credit-card</i>
                    </option>
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Сумма</label>
                <input type="text" wire:model="templateAmount" placeholder="Сумма"
                    class="w-full p-2 border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Тип операции</label>
                <select wire:model.live="type" class="w-full p-2 border rounded" disabled>
                    <option value="1">Приход</option>
                    <option value="0">Расход</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Категория</label>
                <select wire:model="categoryId" class="w-full p-2 border rounded" disabled>
                    <option value="">Выберите категорию</option>
                    @foreach ($filteredCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Дата операции</label>
                <input type="date" wire:model="templateTransactionDate" class="w-full p-2 border rounded">
            </div>

            <div class="mb-2">
                <label class="block mb-1">Примечание</label>
                <textarea wire:model="templateNote" placeholder="Примечание" class="w-full p-2 border rounded"></textarea>
            </div>

            <div class="mb-2">
                @include('components.client-search')
            </div>

            <div class="mb-2">
                <label class="block mb-1">Касса</label>
                <select wire:model="cashId" class="w-full p-2 border rounded">
                    <option value="">Выберите кассу</option>
                    @foreach ($cashRegisters as $register)
                        <option value="{{ $register->id }}">{{ $register->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mb-2">
                <label class="block mb-1">Проект</label>
                <select wire:model="projectId" class="w-full p-2 border rounded">
                    <option value="">Выберите проект</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="mt-4 flex justify-start space-x-2">
                <button wire:click="saveAppliedTemplate" class="bg-green-500 text-white px-4 py-2 rounded">
                    <i class="fas fa-save"></i>
                </button>
            </div>
        </div>
    </div>
</div>
