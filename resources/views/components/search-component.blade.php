<div class="relative">
    <input type="text" wire:model="query" placeholder="Поиск..." class="border rounded p-2">
    @if (!empty($results))
        <div class="absolute bg-white border rounded mt-2 w-full z-10">
            @foreach ($results as $result)
                <div class="p-2 border-b">
                    {{ $result->name ?? $result->first_name . ' ' . $result->last_name }}
                </div>
            @endforeach
        </div>
    @endif
</div>
