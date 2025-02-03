<div class="flex items-center">
    <select wire:model.change="dateFilter" class="w-full p-2 border rounded">
        <option value="all_time">За все время</option>
        <option value="today">Сегодня</option>
        <option value="yesterday">Вчера</option>
        <option value="this_week">Эта неделя</option>
        <option value="this_month">Этот месяц</option>
        <option value="last_week">Прошлая неделя</option>
        <option value="last_month">Прошлый месяц</option>
        <option value="custom">Выбрать даты</option>
    </select>

    @if ($dateFilter == 'custom')
        <div class="flex space-x-2  items-center ml-4">
            <div>
                <input type="date" wire:model.change="startDate" class="w-full p-2 border rounded">
            </div>
            <div>
                <input type="date" wire:model.change="endDate" class="w-full p-2 border rounded">
            </div>
        </div>
    @endif
</div>
