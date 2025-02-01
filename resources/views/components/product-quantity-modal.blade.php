<div id="modalBackground"
    class="fixed overflow-y-auto inset-0 bg-gray-900 bg-opacity-50 z-40 transition-opacity duration-500 {{ $showPForm ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none' }}"
    wire:click="closePForm">
    <div id="form"
        class="fixed top-0 right-0 w-1/4 h-full bg-white shadow-lg transform transition-transform duration-500 ease-in-out z-50 container mx-auto p-4"
        style="transform: {{ $showPForm ? 'translateX(0)' : 'translateX(100%)' }};" wire:click.stop>
        <button wire:click="closePForm" class="absolute top-4 right-4 text-gray-500 hover:text-gray-700 text-2xl"
            style="right: 1rem;">
            &times;
        </button>
        <h2 class="text-xl font-bold mb-4">Добавить товары</h2>
        <div class="mb-4">
            <label>Количество</label>
            <input type="number" wire:model="productQuantity" class="w-full border rounded mb-4">
            @if (isset($productPrice))
                <label>Цена</label>
                <input type="number" wire:model="productPrice" class="w-full border rounded mb-4">
            @endif
            @if (isset($productDiscount))
                <label>Тип скидки</label>
                <select wire:model="discountType" class="w-full border rounded mb-4">
                    <option value="fixed">Фиксированная</option>
                    <option value="percent">Процент</option>
                </select>
                <label>Скидка</label>
                <input type="number" wire:model="productDiscount" class="w-full border rounded mb-4">
            @endif
        </div>
        <div>
            <button wire:click="saveProductModal" class="bg-green-500 text-white px-4 py-2 rounded mr-2">
                <i class="fa fa-save"></i>
            </button>
        </div>
    </div>
</div>
