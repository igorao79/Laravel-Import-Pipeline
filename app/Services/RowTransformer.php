<?php

namespace App\Services;

/**
 * Трансформирует сырые данные CSV в формат, готовый для БД.
 * Нормализация, очистка, маппинг колонок.
 */
class RowTransformer
{
    /**
     * Трансформирует одну строку.
     */
    public function transform(array $row, ?array $columnMapping = null): array
    {
        // 1. Маппинг колонок (CSV "Название" → БД "name")
        if ($columnMapping) {
            $row = $this->applyMapping($row, $columnMapping);
        }

        // 2. Нормализация данных
        return [
            'name'     => trim($row['name'] ?? ''),
            'sku'      => strtoupper(trim($row['sku'] ?? '')),
            'price'    => $this->normalizePrice($row['price'] ?? '0'),
            'quantity' => (int) ($row['qty'] ?? $row['quantity'] ?? 0),
            'category' => trim($row['category'] ?? '') ?: null,
        ];
    }

    /**
     * Трансформирует пачку строк.
     *
     * @param array<int, array> $rows
     * @return array<int, array>
     */
    public function transformBatch(array $rows, ?array $columnMapping = null): array
    {
        $result = [];
        foreach ($rows as $rowNumber => $row) {
            $result[$rowNumber] = $this->transform($row, $columnMapping);
        }

        return $result;
    }

    /**
     * Применяет маппинг колонок: {"Название": "name", "Артикул": "sku"}
     */
    private function applyMapping(array $row, array $mapping): array
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            $newKey = $mapping[$key] ?? $key;
            $mapped[$newKey] = $value;
        }

        return $mapped;
    }

    /**
     * Нормализует цену: "1 500,50" → 1500.50
     */
    private function normalizePrice(string $price): float
    {
        // Убираем пробелы и заменяем запятую на точку
        $price = str_replace([' ', "\xC2\xA0"], '', $price); // обычный пробел + неразрывный
        $price = str_replace(',', '.', $price);

        return round((float) $price, 2);
    }
}
