<?php

namespace Tests\Unit\Services;

use App\Services\RowValidator;
use Tests\TestCase;

class RowValidatorTest extends TestCase
{
    private RowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RowValidator();
    }

    // ── Одиночная валидация ──────────────────────────────────

    public function test_valid_row_passes(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget Pro',
            'sku' => 'WP-001',
            'price' => '29.99',
            'qty' => '100',
            'category' => 'Tools',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_empty_name_fails(): void
    {
        $result = $this->validator->validate([
            'name' => '',
            'sku' => 'WP-001',
            'price' => '29.99',
            'qty' => '10',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function test_empty_sku_fails(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget',
            'sku' => '',
            'price' => '29.99',
            'qty' => '10',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('sku', $result['errors']);
    }

    public function test_non_numeric_price_fails(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget',
            'sku' => 'W001',
            'price' => 'not-a-number',
            'qty' => '10',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('price', $result['errors']);
    }

    public function test_negative_price_fails(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget',
            'sku' => 'W001',
            'price' => '-5.00',
            'qty' => '10',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('price', $result['errors']);
    }

    public function test_zero_price_passes(): void
    {
        $result = $this->validator->validate([
            'name' => 'Free Widget',
            'sku' => 'FREE-001',
            'price' => '0',
            'qty' => '10',
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_negative_qty_fails(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget',
            'sku' => 'W001',
            'price' => '10',
            'qty' => '-5',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('qty', $result['errors']);
    }

    public function test_non_integer_qty_fails(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget',
            'sku' => 'W001',
            'price' => '10',
            'qty' => '5.5',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('qty', $result['errors']);
    }

    public function test_nullable_category_passes(): void
    {
        $result = $this->validator->validate([
            'name' => 'Widget',
            'sku' => 'W001',
            'price' => '10',
            'qty' => '5',
            'category' => null,
        ]);

        $this->assertTrue($result['valid']);
    }

    public function test_multiple_errors_returned_at_once(): void
    {
        $result = $this->validator->validate([
            'name' => '',
            'sku' => '',
            'price' => 'abc',
            'qty' => '-10',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertCount(4, $result['errors']); // name, sku, price, qty
    }

    public function test_name_exceeding_max_length_fails(): void
    {
        $result = $this->validator->validate([
            'name' => str_repeat('A', 256),
            'sku' => 'W001',
            'price' => '10',
            'qty' => '5',
        ]);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    // ── Batch-валидация ──────────────────────────────────────

    public function test_validate_batch_separates_passed_and_failed(): void
    {
        $rows = [
            2 => ['name' => 'Good', 'sku' => 'G001', 'price' => '10', 'qty' => '5', 'category' => 'A'],
            3 => ['name' => '',     'sku' => '',     'price' => 'x',  'qty' => '-1', 'category' => ''],
            4 => ['name' => 'Also Good', 'sku' => 'G002', 'price' => '20', 'qty' => '10', 'category' => 'B'],
        ];

        $result = $this->validator->validateBatch($rows);

        $this->assertCount(2, $result['passed']);
        $this->assertCount(1, $result['failed']);
        $this->assertArrayHasKey(2, $result['passed']);
        $this->assertArrayHasKey(4, $result['passed']);
        $this->assertArrayHasKey(3, $result['failed']);
    }

    public function test_validate_batch_preserves_row_numbers(): void
    {
        $rows = [
            10 => ['name' => 'A', 'sku' => 'A1', 'price' => '1', 'qty' => '1', 'category' => ''],
            25 => ['name' => '', 'sku' => '', 'price' => '', 'qty' => '', 'category' => ''],
            99 => ['name' => 'B', 'sku' => 'B1', 'price' => '2', 'qty' => '2', 'category' => ''],
        ];

        $result = $this->validator->validateBatch($rows);

        $this->assertArrayHasKey(10, $result['passed']);
        $this->assertArrayHasKey(99, $result['passed']);
        $this->assertArrayHasKey(25, $result['failed']);
    }

    public function test_validate_batch_failed_contains_error_details(): void
    {
        $rows = [
            5 => ['name' => '', 'sku' => 'OK', 'price' => '10', 'qty' => '1', 'category' => ''],
        ];

        $result = $this->validator->validateBatch($rows);

        $this->assertArrayHasKey('data', $result['failed'][5]);
        $this->assertArrayHasKey('errors', $result['failed'][5]);
        $this->assertArrayHasKey('name', $result['failed'][5]['errors']);
    }

    public function test_validate_batch_all_valid(): void
    {
        $rows = [
            1 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => ''],
            2 => ['name' => 'B', 'sku' => 'B1', 'price' => '20', 'qty' => '2', 'category' => ''],
        ];

        $result = $this->validator->validateBatch($rows);

        $this->assertCount(2, $result['passed']);
        $this->assertEmpty($result['failed']);
    }

    public function test_validate_batch_all_invalid(): void
    {
        $rows = [
            1 => ['name' => '', 'sku' => '', 'price' => 'x', 'qty' => '-1', 'category' => ''],
            2 => ['name' => '', 'sku' => '', 'price' => 'y', 'qty' => '-2', 'category' => ''],
        ];

        $result = $this->validator->validateBatch($rows);

        $this->assertEmpty($result['passed']);
        $this->assertCount(2, $result['failed']);
    }

    public function test_validate_batch_empty_input(): void
    {
        $result = $this->validator->validateBatch([]);

        $this->assertEmpty($result['passed']);
        $this->assertEmpty($result['failed']);
    }
}
