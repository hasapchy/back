@if (session()->has('success'))
    <div class="fixed top-4 right-4 bg-green-600 text-white p-4 rounded shadow-lg z-50 alert" role="alert"
        x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-transition>
        <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-white"
            onclick="this.parentElement.style.display='none';">
            ×
        </button>
        {{ session('success') }}
    </div>
@endif

@if (session()->has('message'))
    <div class="fixed top-4 right-4 bg-yellow-600 text-white p-4 rounded shadow-lg z-50 alert" role="alert"
        x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-transition>
        <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-white"
            onclick="this.parentElement.style.display='none';">
            ×
        </button>
        {{ session('message') }}
    </div>
@endif

@if (session()->has('error'))
    <div class="fixed top-4 right-4 bg-red-600 text-white p-4 rounded shadow-lg z-50 alert" role="alert"
        x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-transition>
        <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-white"
            onclick="this.parentElement.style.display='none';">
            ×
        </button>
        {{ session('error') }}
    </div>
@endif

@if ($errors->any())
    <div class="fixed top-4 right-4 bg-red-600 text-white p-4 rounded shadow-lg z-50 alert" role="alert"
        x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-transition>
        <button type="button" class="absolute top-0 right-0 mt-2 mr-2 text-white"
            onclick="this.parentElement.style.display='none';">
            ×
        </button>
        <ul class="list-disc list-inside">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
