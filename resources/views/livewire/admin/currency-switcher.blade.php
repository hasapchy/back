<div>
    <select wire:model.lazy="selectedCurrency">
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