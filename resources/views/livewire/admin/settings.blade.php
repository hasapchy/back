@section('page-title', 'Настройки')
@section('showSearch', false)
<div class="mx-auto p-4">
    @include('components.alert')
    <div class="mb-4">
        <label for="companyName" class="block text-sm font-medium">Название компании:</label>
        <input type="text" id="companyName" wire:model.defer="companyName" class="w-full p-2 border rounded">

    </div>

    <div class="mb-4">
        <label for="companyLogo" class="block text-sm font-medium">Логотип компании:</label>
        <input type="file" id="companyLogo" wire:model="companyLogo" class="w-full p-2 border rounded">
        @if ($companyLogo instanceof \Illuminate\Http\UploadedFile)
            <img src="{{ $companyLogo->temporaryUrl() }}" alt="Предпросмотр логотипа" class="mt-2 h-20">
        @elseif ($companyLogo)
            <img src="{{ asset('storage/' . $companyLogo) }}" alt="Логотип компании" class="mt-2 h-20">
        @endif

    </div>

    <button wire:click="saveSettings" class="bg-[#5CB85C] text-white px-4 py-2 rounded">Сохранить</button>
</div>
