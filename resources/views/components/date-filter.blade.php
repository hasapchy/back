<div class="flex items-center">
    <div class="relative w-full">
        <select wire:model.change="dateFilter" class="w-full p-2 pl-10 border rounded">
            <option value="all_time">За все время</option>
            <option value="today">Сегодня</option>
            <option value="yesterday">Вчера</option>
            <option value="this_week">Эта неделя</option>
            <option value="this_month">Этот месяц</option>
            <option value="last_week">Прошлая неделя</option>
            <option value="last_month">Прошлый месяц</option>
            <option value="custom">Выбрать даты</option>
        </select>
        <i class="fas fa-calendar-alt absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
    </div>

    @if ($dateFilter == 'custom')
        <div class="flex space-x-2 items-center ml-4">
            <input type="date" wire:model.change="startDate" class="w-full p-2 border rounded">
            <input type="date" wire:model.change="endDate" class="w-full p-2 border rounded">
        </div>
    @endif
</div>
