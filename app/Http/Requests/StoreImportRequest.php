<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt,xlsx,xls',
                'max:51200', // 50 MB
            ],
            'column_mapping' => ['nullable', 'json'],
            'chunk_size' => ['nullable', 'integer', 'min:100', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'Максимальный размер файла — 50 MB',
            'file.mimes' => 'Допустимые форматы: CSV, TXT, XLSX, XLS',
        ];
    }
}
