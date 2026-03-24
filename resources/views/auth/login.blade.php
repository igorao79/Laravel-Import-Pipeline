@extends('layouts.app')

@section('content')
<div class="flex min-h-[60vh] items-center justify-center">
    <div class="w-full max-w-sm">
        <h2 class="text-center text-2xl font-bold text-gray-900 mb-8">Войти в систему</h2>

        <form method="POST" action="/login" class="bg-white shadow rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                       class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Пароль</label>
                <input type="password" name="password" id="password" required
                       class="w-full rounded-lg border-gray-300 border px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition">
                Войти
            </button>
        </form>

        <p class="mt-4 text-center text-xs text-gray-400">
            test@example.com / password
        </p>
    </div>
</div>
@endsection
