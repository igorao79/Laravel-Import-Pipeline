<!DOCTYPE html>
<html lang="ru" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Import Pipeline' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="h-full">
    <div class="min-h-full">
        <nav class="bg-indigo-600 shadow-lg">
            <div class="mx-auto max-w-5xl px-4 py-3 flex items-center justify-between">
                <a href="/imports" class="text-white font-bold text-lg flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    Import Pipeline
                </a>
                @auth
                    <div class="flex items-center gap-4">
                        <span class="text-indigo-200 text-sm">{{ auth()->user()->name }}</span>
                        <form method="POST" action="/logout">
                            @csrf
                            <button class="text-indigo-200 hover:text-white text-sm">Выйти</button>
                        </form>
                    </div>
                @endauth
            </div>
        </nav>

        <main class="mx-auto max-w-5xl px-4 py-8">
            @if(session('success'))
                <div class="mb-4 rounded-lg bg-green-50 border border-green-200 p-4 text-green-800 text-sm">
                    {{ session('success') }}
                </div>
            @endif

            @if(session('error'))
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-red-800 text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 border border-red-200 p-4 text-red-800 text-sm">
                    <ul class="list-disc list-inside">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</body>
</html>
