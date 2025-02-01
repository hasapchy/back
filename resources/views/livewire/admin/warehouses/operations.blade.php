@section('page-title', 'Сток')
<div class="container mx-auto p-4">
    @include('components.alert')
    <div class="flex items-center space-x-4 mb-4">
        @if (Auth::user()->hasPermission('create_warehouses'))
            <a href="{{ route('admin.warehouses.index') }}" class="bg-green-500 text-white px-4 py-2 rounded">
                <i class="fas fa-plus"></i>
            </a>
        @endif
        @include('components.warehouse-accordion')

        <div>
            <select wire:model.live="selectedWarehouse" class="w-64 p-2 border rounded">
                <option value="">Выберите склад</option>
                @foreach ($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <select wire:model.live="categoryFilter" class="w-64 p-2 border rounded">
                <option value="">Все категории</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <table class="min-w-full bg-white shadow-md rounded mb-6">
        <thead class="bg-gray-100">
            <tr>
                <th class="py-2 px-4 border">Склад</th>
                <th class="py-2 px-4 border">Товар</th>
                <th class="py-2 px-4 border">Количество</th>
                <th class="py-2 px-4 border">Категория</th>

            </tr>
        </thead>
        <tbody>
            @foreach ($stockData as $stock)
                <tr>
                    <td class="py-2 px-4 border">{{ $stock['warehouse'] }}</td>
                    <td class="py-2 px-4 border">{{ $stock['name'] }}</td>
                    <td class="py-2 px-4 border">{{ $stock['quantity'] }} шт</td>
                    <td class="py-2 px-4 border">{{ $stock['category'] }}</td>

                </tr>
            @endforeach
        </tbody>
    </table>
</div>
