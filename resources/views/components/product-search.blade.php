<div class="mb-4" x-data="{ showProductDropdown: false }">
    <label class="block mb-1">Поиск товара</label>
    <input type="text" x-model="productSearch" wire:model.live.debounce.250ms="productSearch"
        placeholder="Введите название или артикул товара" class="w-full p-2 border rounded"
        @focus="showProductDropdown = true; $wire.call('showAllProducts')"
        @blur="setTimeout(() => showProductDropdown = false, 200)">
    <ul x-show="showProductDropdown"
        class="absolute bg-white border rounded shadow-lg max-h-40 overflow-y-auto w-full mt-1 z-10">
        @forelse ($productResults as $product)
            <li wire:click="selectProduct({{ $product->id }})" @click="showProductDropdown = false"
                class="cursor-pointer p-2 border-b hover:bg-gray-100">
                {{ $product->name }} (Артикул: {{ $product->sku }})
            </li>
        @empty
            <li class="p-2 text-gray-500">Товары не найдены/не выбран склад/товаров нет</li>
        @endforelse
    </ul>
</div>
