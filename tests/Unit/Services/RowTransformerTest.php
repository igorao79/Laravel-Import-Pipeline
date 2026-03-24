<?php

namespace Tests\Unit\Services;

use App\Services\RowTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RowTransformerTest extends TestCase
{
    private RowTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new RowTransformer();
    }

    // ── Базовая трансформация ────────────────────────────────

    public function test_transforms_valid_row(): void
    {
        $result = $this->transformer->transform([
            'name' => 'Widget',
            'sku' => 'W001',
            'price' => '29.99',
            'qty' => '100',
            'category' => 'Tools',
        ]);

        $this->assertEquals('Widget', $result['name']);
        $this->assertEquals('W001', $result['sku']);
        $this->assertEquals(29.99, $result['price']);
        $this->assertEquals(100, $result['quantity']); // qty → quantity
        $this->assertEquals('Tools', $result['category']);
    }

    // ── Нормализация имени ───────────────────────────────────

    public function test_trims_name_whitespace(): void
    {
        $result = $this->transformer->transform([
            'name' => '  Widget Pro  ',
            'sku' => 'W001',
            'price' => '10',
            'qty' => '1',
            'category' => '',
        ]);

        $this->assertEquals('Widget Pro', $result['name']);
    }

    // ── Нормализация SKU ─────────────────────────────────────

    public function test_converts_sku_to_uppercase(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'abc-123',
            'price' => '10',
            'qty' => '1',
            'category' => '',
        ]);

        $this->assertEquals('ABC-123', $result['sku']);
    }

    public function test_trims_sku_whitespace(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => '  w001  ',
            'price' => '10',
            'qty' => '1',
            'category' => '',
        ]);

        $this->assertEquals('W001', $result['sku']);
    }

    // ── Нормализация цены ────────────────────────────────────

    #[DataProvider('priceProvider')]
    public function test_normalizes_price(string $input, float $expected): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => $input,
            'qty' => '1',
            'category' => '',
        ]);

        $this->assertEquals($expected, $result['price']);
    }

    public static function priceProvider(): array
    {
        return [
            'обычная цена'           => ['29.99', 29.99],
            'русский формат'         => ['1 500,50', 1500.50],
            'с пробелами'            => ['1 000 000', 1000000.0],
            'только запятая'         => ['19,99', 19.99],
            'целое число'            => ['100', 100.0],
            'ноль'                   => ['0', 0.0],
            'с неразрывным пробелом' => ["1\xC2\xA0500,00", 1500.0],
            'дробная часть 1 знак'   => ['10,5', 10.5],
        ];
    }

    // ── Нормализация количества ──────────────────────────────

    public function test_converts_qty_to_integer(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => '10',
            'qty' => '42',
            'category' => '',
        ]);

        $this->assertIsInt($result['quantity']);
        $this->assertEquals(42, $result['quantity']);
    }

    public function test_handles_quantity_field_name(): void
    {
        // Файл может иметь колонку "quantity" вместо "qty"
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => '10',
            'quantity' => '77',
            'category' => '',
        ]);

        $this->assertEquals(77, $result['quantity']);
    }

    public function test_missing_qty_defaults_to_zero(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => '10',
            'category' => '',
        ]);

        $this->assertEquals(0, $result['quantity']);
    }

    // ── Нормализация категории ───────────────────────────────

    public function test_empty_category_becomes_null(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => '10',
            'qty' => '1',
            'category' => '',
        ]);

        $this->assertNull($result['category']);
    }

    public function test_whitespace_only_category_becomes_null(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => '10',
            'qty' => '1',
            'category' => '   ',
        ]);

        $this->assertNull($result['category']);
    }

    public function test_category_is_trimmed(): void
    {
        $result = $this->transformer->transform([
            'name' => 'A',
            'sku' => 'A1',
            'price' => '10',
            'qty' => '1',
            'category' => '  Electronics  ',
        ]);

        $this->assertEquals('Electronics', $result['category']);
    }

    // ── Маппинг колонок ──────────────────────────────────────

    public function test_applies_column_mapping(): void
    {
        $mapping = [
            'Название' => 'name',
            'Артикул' => 'sku',
            'Цена' => 'price',
            'Количество' => 'qty',
            'Категория' => 'category',
        ];

        $result = $this->transformer->transform([
            'Название' => 'Виджет',
            'Артикул' => 'v001',
            'Цена' => '500,00',
            'Количество' => '10',
            'Категория' => 'Инструменты',
        ], $mapping);

        $this->assertEquals('Виджет', $result['name']);
        $this->assertEquals('V001', $result['sku']);
        $this->assertEquals(500.0, $result['price']);
        $this->assertEquals(10, $result['quantity']);
        $this->assertEquals('Инструменты', $result['category']);
    }

    public function test_partial_column_mapping_keeps_unmapped(): void
    {
        $mapping = ['Название' => 'name'];

        $result = $this->transformer->transform([
            'Название' => 'Виджет',
            'sku' => 'v001',
            'price' => '100',
            'qty' => '5',
            'category' => 'Misc',
        ], $mapping);

        $this->assertEquals('Виджет', $result['name']);
        $this->assertEquals('V001', $result['sku']);
    }

    // ── Batch-трансформация ──────────────────────────────────

    public function test_transform_batch_processes_all_rows(): void
    {
        $rows = [
            2 => ['name' => 'A', 'sku' => 'a1', 'price' => '10', 'qty' => '1', 'category' => ''],
            5 => ['name' => 'B', 'sku' => 'b2', 'price' => '20', 'qty' => '2', 'category' => 'X'],
        ];

        $result = $this->transformer->transformBatch($rows);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(2, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertEquals('A1', $result[2]['sku']);
        $this->assertEquals('B2', $result[5]['sku']);
    }

    public function test_transform_batch_with_mapping(): void
    {
        $mapping = ['Имя' => 'name'];

        $rows = [
            1 => ['Имя' => 'Test', 'sku' => 'T1', 'price' => '5', 'qty' => '1', 'category' => ''],
        ];

        $result = $this->transformer->transformBatch($rows, $mapping);

        $this->assertEquals('Test', $result[1]['name']);
    }

    public function test_transform_batch_empty_input(): void
    {
        $result = $this->transformer->transformBatch([]);

        $this->assertEmpty($result);
    }
}
