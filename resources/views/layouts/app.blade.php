<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>


    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="font-sans antialiased" onload="initializeSidebar()">

    <div class="flex min-h-screen">
        @auth
            <x-sidebar id="main-sidebar" />
            <x-settings-sidebar />
            <x-alert />
        @endauth

        <div id="main-content" class="flex-1 transition-transform duration-300">

            @include('layouts.navigation')
            <main class="pt-0">
                @if (isset($header))
                    <header class="bg-white shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endif
                @yield('content')
                {{ $slot ?? '' }}
            </main>
        </div>
    </div>


    @stack('scripts')

    <script>
        function initializeSidebar() {
            const settingsButton = document.getElementById('settings-button');
            const settingsSidebar = document.getElementById('settings-sidebar');

            if (settingsButton && settingsSidebar) {
                settingsButton.addEventListener('click', () => {
                    settingsSidebar.classList.toggle('hidden');
                });
            }
        }
    </script>
</body>

</html>
