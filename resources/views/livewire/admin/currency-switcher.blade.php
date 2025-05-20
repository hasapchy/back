<div class="flex mr-4">
    <select wire:model.lazy="selectedCurrency" class="appearance-none bg-none border rounded px-2 py-1">
        @foreach($currencies as $currency)
            <option value="{{ $currency->code }}">{{ $currency->symbol }}</option>
        @endforeach
    </select>
</div>
<script>
    window.addEventListener('currency-changed', event => {
        location.reload();
    });
</script>