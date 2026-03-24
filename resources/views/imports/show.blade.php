@extends('layouts.app')

@php
    $colors = [
        'pending' => 'bg-gray-100 text-gray-700 border-gray-300',
        'processing' => 'bg-blue-100 text-blue-700 border-blue-300',
        'completed' => 'bg-green-100 text-green-700 border-green-300',
        'failed' => 'bg-red-100 text-red-700 border-red-300',
        'completed_with_errors' => 'bg-yellow-100 text-yellow-700 border-yellow-300',
    ];
    $labels = [
        'pending' => 'Ожидает',
        'processing' => 'В обработке',
        'completed' => 'Завершён',
        'failed' => 'Ошибка',
        'completed_with_errors' => 'С ошибками',
    ];
    $status = $import->status->value;
    $isProcessing = in_array($status, ['pending', 'processing']);
    $progressBarColor = match($status) {
        'completed' => 'bg-green-500',
        'failed' => 'bg-red-500',
        'completed_with_errors' => 'bg-yellow-500',
        default => 'bg-indigo-600',
    };
@endphp

@section('content')
<div x-data="{ polling: {{ $isProcessing ? 'true' : 'false' }} }"
     x-init="if (polling) { setInterval(() => location.reload(), 2000) }">

    {{-- Header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <a href="/imports" class="text-sm text-indigo-600 hover:text-indigo-800 mb-1 inline-block">← Назад к списку</a>
            <h1 class="text-2xl font-bold text-gray-900">{{ $import->original_filename }}</h1>
        </div>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium border {{ $colors[$status] ?? '' }}">
            @if($isProcessing)
                <svg class="animate-spin -ml-0.5 mr-2 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            @endif
            {{ $labels[$status] ?? $status }}
        </span>
    </div>

    {{-- Progress bar --}}
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-medium text-gray-700">Прогресс</span>
            <span class="text-2xl font-bold text-gray-900">{{ $import->progressPercent() }}%</span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
            <div class="{{ $progressBarColor }} h-4 rounded-full transition-all duration-500 ease-out"
                 style="width: {{ $import->progressPercent() }}%"></div>
        </div>
    </div>

    {{-- Stats cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-sm text-gray-500">Всего строк</div>
            <div class="text-2xl font-bold text-gray-900">{{ number_format($import->total_rows) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-sm text-gray-500">Обработано</div>
            <div class="text-2xl font-bold text-green-600">{{ number_format($import->processed_rows) }}</div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-sm text-gray-500">Ошибки</div>
            <div class="text-2xl font-bold {{ $import->failed_rows > 0 ? 'text-red-600' : 'text-gray-400' }}">
                {{ number_format($import->failed_rows) }}
            </div>
        </div>
        <div class="bg-white rounded-xl shadow p-4">
            <div class="text-sm text-gray-500">Чанки</div>
            <div class="text-2xl font-bold text-gray-900">{{ $import->completed_chunks }}/{{ $import->total_chunks }}</div>
        </div>
    </div>

    {{-- Meta info --}}
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
            <div><span class="text-gray-500">Размер чанка:</span> <span class="font-medium">{{ $import->chunk_size }}</span></div>
            <div><span class="text-gray-500">Начало:</span> <span class="font-medium">{{ $import->started_at?->format('d.m.Y H:i:s') ?? '—' }}</span></div>
            <div><span class="text-gray-500">Завершение:</span> <span class="font-medium">{{ $import->completed_at?->format('d.m.Y H:i:s') ?? '—' }}</span></div>
        </div>
    </div>

    {{-- Retry button --}}
    @if($import->failed_rows > 0 && !$isProcessing)
        <div class="mb-6">
            <form method="POST" action="/imports/{{ $import->id }}/retry">
                @csrf
                <button type="submit"
                        class="rounded-lg bg-yellow-500 px-6 py-2.5 text-sm font-semibold text-white hover:bg-yellow-400 transition">
                    Повторить обработку {{ $import->failed_rows }} ошибочных строк
                </button>
            </form>
        </div>
    @endif

    {{-- Failed rows table --}}
    @if($failedRows->isNotEmpty())
        <div class="bg-white rounded-xl shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Ошибки импорта</h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Строка</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Данные</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ошибки</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($failedRows as $row)
                    <tr>
                        <td class="px-4 py-3 text-sm text-gray-500 font-mono">{{ $row->row_number }}</td>
                        <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate">
                            <code class="text-xs bg-gray-100 rounded px-1 py-0.5">
                                {{ json_encode($row->original_data, JSON_UNESCAPED_UNICODE) }}
                            </code>
                        </td>
                        <td class="px-4 py-3">
                            @foreach($row->errors as $field => $messages)
                                <div class="text-xs text-red-600">
                                    <strong>{{ $field }}:</strong> {{ implode(', ', (array) $messages) }}
                                </div>
                            @endforeach
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            @if($failedRows->hasPages())
                <div class="px-6 py-4 border-t border-gray-200">
                    {{ $failedRows->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
