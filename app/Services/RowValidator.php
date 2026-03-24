<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/**
 * Валидирует строки импорта.
 * Правила зависят от типа импортируемых данных (products, users и т.д.)
 */
class RowValidator
{
    /**
     * Rules for raw (pre-transform) data.
     */
    private array $rules = [
        'name'  => ['required', 'string', 'max:255'],
        'sku'   => ['required', 'string', 'max:100'],
        'price' => ['required', 'numeric', 'min:0'],
        'qty'   => ['required', 'integer', 'min:0'],
        'category' => ['nullable', 'string', 'max:100'],
    ];

    /**
     * Rules for transformed data (post-transform).
     * Keys differ: qty → quantity, price is already float.
     */
    private array $transformedRules = [
        'name'     => ['required', 'string', 'max:255'],
        'sku'      => ['required', 'string', 'max:100'],
        'price'    => ['required', 'numeric', 'min:0'],
        'quantity' => ['required', 'integer', 'min:0'],
        'category' => ['nullable', 'string', 'max:100'],
    ];

    private array $messages = [
        'name.required'  => 'Название товара обязательно',
        'sku.required'   => 'Артикул обязателен',
        'price.numeric'  => 'Цена должна быть числом',
        'price.min'      => 'Цена не может быть отрицательной',
        'qty.integer'    => 'Количество должно быть целым числом',
        'quantity.integer' => 'Количество должно быть целым числом',
    ];

    /**
     * Validates raw (pre-transform) row.
     *
     * @return array{valid: bool, errors: array<string, string[]>}
     */
    public function validate(array $row): array
    {
        $validator = Validator::make($row, $this->rules, $this->messages);

        return [
            'valid' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    /**
     * Validates transformed row (post-transform).
     *
     * @return array{valid: bool, errors: array<string, string[]>}
     */
    public function validateTransformed(array $row): array
    {
        $validator = Validator::make($row, $this->transformedRules, $this->messages);

        return [
            'valid' => $validator->passes(),
            'errors' => $validator->errors()->toArray(),
        ];
    }

    /**
     * Валидирует пачку строк — эффективнее чем по одной.
     *
     * @param array<int, array> $rows ключ = номер строки
     * @param bool $transformed true if rows are already transformed
     * @return array{passed: array<int, array>, failed: array<int, array>}
     */
    public function validateBatch(array $rows, bool $transformed = false): array
    {
        $passed = [];
        $failed = [];

        foreach ($rows as $rowNumber => $row) {
            $result = $transformed
                ? $this->validateTransformed($row)
                : $this->validate($row);

            if ($result['valid']) {
                $passed[$rowNumber] = $row;
            } else {
                $failed[$rowNumber] = [
                    'data' => $row,
                    'errors' => $result['errors'],
                ];
            }
        }

        return compact('passed', 'failed');
    }
}
