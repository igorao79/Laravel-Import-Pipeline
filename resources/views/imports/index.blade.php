@extends('layouts.app')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Импорты</h1>
</div>

{{-- Форма загрузки --}}
<div class="bg-white rounded-xl shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Загрузить файл</h2>
    <form method="POST" action="/imports" enctype="multipart/form-data"
          class="flex flex-col sm:flex-row items-start sm:items-end gap-4"
          x-data="{ filename: '' }">
        @csrf

        <div class="flex-1 w-full">
            <label class="block text-sm font-medium text-gray-600 mb-1">CSV / Excel файл</label>
            <div class="relative">
                <input type="file" name="file" required accept=".csv,.txt,.xlsx,.xls"
                       @change="filename = $event.target.files[0]?.name || ''"
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 cursor-pointer">
            </div>
        </div>

        <div class="w-full sm:w-auto">
            <label class="block text-sm font-medium text-gray-600 mb-1">Размер чанка</label>
            <input type="number" name="chunk_size" value="500" min="10" max="5000"
                   class="w-full sm:w-28 rounded-lg border-gray-300 border px-3 py-2 text-sm">
        </div>

        <button type="submit"
                class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-indigo-500 transition whitespace-nowrap">
            Загрузить
        </button>
    </form>
</div>

{{-- Таблица импортов --}}
@if($imports->isEmpty())
    <div class="text-center py-12 text-gray-400">
        <svg class="mx-auto h-12 w-12 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p>Ещё нет импортов. Загрузите CSV файл.</p>
    </div>
@else
    <div class="bg-white rounded-xl shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Файл</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Прогресс</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Строки</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Дата</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($imports as $import)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm text-gray-500">{{ $import->id }}</td>
                    <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ $import->original_filename }}</td>
                    <td class="px-4 py-3">
                        @php
                            $colors = [
                                'pending' => 'bg-gray-100 text-gray-700',
                                'processing' => 'bg-blue-100 text-blue-700',
                                'completed' => 'bg-green-100 text-green-700',
                                'failed' => 'bg-red-100 text-red-700',
                                'completed_with_errors' => 'bg-yellow-100 text-yellow-700',
                            ];
                            $labels = [
                                'pending' => 'Ожидает',
                                'processing' => 'В обработке',
                                'completed' => 'Завершён',
                                'failed' => 'Ошибка',
                                'completed_with_errors' => 'С ошибками',
                            ];
                            $status = $import->status->value;
                        @endphp
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $colors[$status] ?? 'bg-gray-100 text-gray-700' }}">
                            {{ $labels[$status] ?? $status }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-20 bg-gray-200 rounded-full h-2">
                                <div class="bg-indigo-600 h-2 rounded-full transition-all" style="width: {{ $import->progressPercent() }}%"></div>
                            </div>
                            <span class="text-xs text-gray-500">{{ $import->progressPercent() }}%</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500">
                        {{ $import->processed_rows }}/{{ $import->total_rows }}
                        @if($import->failed_rows > 0)
                            <span class="text-red-500">({{ $import->failed_rows }} ош.)</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-400">{{ $import->created_at->format('d.m.Y H:i') }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="/imports/{{ $import->id }}" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">
                            Подробнее →
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $imports->links() }}
    </div>
@endif
@endsection
