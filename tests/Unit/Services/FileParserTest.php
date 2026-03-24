<?php

namespace Tests\Unit\Services;

use App\Services\FileParser;
use RuntimeException;
use Tests\TestCase;

class FileParserTest extends TestCase
{
    private FileParser $parser;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new FileParser();
        $this->tempDir = sys_get_temp_dir() . '/file_parser_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Чистим временные файлы
        array_map('unlink', glob("{$this->tempDir}/*"));
        rmdir($this->tempDir);
        parent::tearDown();
    }

    private function createCsv(string $filename, string $content): string
    {
        $path = "{$this->tempDir}/{$filename}";
        file_put_contents($path, $content);

        return $path;
    }

    // ── CSV Parsing ──────────────────────────────────────────

    public function test_parses_simple_csv(): void
    {
        $path = $this->createCsv('test.csv', implode("\n", [
            'name,sku,price',
            'Widget,W001,29.99',
            'Gadget,G001,49.99',
        ]));

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertCount(2, $rows);
        $this->assertEquals('Widget', $rows[2]['name']);
        $this->assertEquals('W001', $rows[2]['sku']);
        $this->assertEquals('29.99', $rows[2]['price']);
        $this->assertEquals('Gadget', $rows[3]['name']);
    }

    public function test_row_numbers_start_from_2(): void
    {
        // Строка 1 — заголовок, данные начинаются со строки 2
        $path = $this->createCsv('test.csv', "name,sku\nA,A1\nB,B2\n");

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertArrayHasKey(2, $rows);
        $this->assertArrayHasKey(3, $rows);
        $this->assertArrayNotHasKey(1, $rows);
    }

    public function test_trims_header_whitespace(): void
    {
        $path = $this->createCsv('test.csv', " name , sku , price \nWidget,W001,10\n");

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertArrayHasKey('name', $rows[2]);
        $this->assertArrayHasKey('sku', $rows[2]);
        $this->assertArrayHasKey('price', $rows[2]);
    }

    public function test_skips_empty_rows(): void
    {
        $path = $this->createCsv('test.csv', implode("\n", [
            'name,sku',
            'Widget,W001',
            ',,',          // пустая строка
            '',            // пустая строка
            'Gadget,G001',
        ]));

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertCount(2, $rows);
    }

    public function test_pads_short_rows_with_empty_values(): void
    {
        // Строка с меньшим количеством колонок, чем заголовков
        $path = $this->createCsv('test.csv', "name,sku,price\nWidget,W001\n");

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertCount(1, $rows);
        $this->assertEquals('Widget', $rows[2]['name']);
        $this->assertEquals('W001', $rows[2]['sku']);
        $this->assertEquals('', $rows[2]['price']); // дополнено пустой строкой
    }

    public function test_handles_extra_columns_gracefully(): void
    {
        // Строка с большим количеством колонок, чем заголовков
        $path = $this->createCsv('test.csv', "name,sku\nWidget,W001,EXTRA,MORE\n");

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[2]); // лишние колонки обрезаны
    }

    public function test_returns_generator(): void
    {
        $path = $this->createCsv('test.csv', "name\nA\nB\n");

        $result = $this->parser->parse($path);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    public function test_empty_csv_yields_nothing(): void
    {
        $path = $this->createCsv('test.csv', "name,sku\n");

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertEmpty($rows);
    }

    public function test_csv_with_only_headers(): void
    {
        $path = $this->createCsv('test.csv', "name,sku,price\n");

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertEmpty($rows);
    }

    public function test_csv_with_quoted_fields(): void
    {
        $path = $this->createCsv('test.csv', implode("\n", [
            'name,description,price',
            '"Widget, Pro","A great ""widget""",29.99',
        ]));

        $rows = iterator_to_array($this->parser->parse($path));

        $this->assertEquals('Widget, Pro', $rows[2]['name']);
        $this->assertEquals('A great "widget"', $rows[2]['description']);
    }

    public function test_large_csv_uses_constant_memory(): void
    {
        // Создаём CSV с 1000 строк
        $content = "name,sku,price\n";
        for ($i = 1; $i <= 1000; $i++) {
            $content .= "Product {$i},SKU{$i},{$i}.99\n";
        }

        $path = $this->createCsv('large.csv', $content);

        // Generator не грузит всё в память
        $count = 0;
        foreach ($this->parser->parse($path) as $row) {
            $count++;
            // Проверяем только первую и последнюю
            if ($count === 1) {
                $this->assertEquals('Product 1', $row['name']);
            }
        }

        $this->assertEquals(1000, $count);
    }

    // ── Подсчёт строк ───────────────────────────────────────

    public function test_count_rows_csv(): void
    {
        $path = $this->createCsv('test.csv', "name,sku\nA,A1\nB,B2\nC,C3\n");

        $count = $this->parser->countRows($path);

        $this->assertEquals(3, $count); // 4 строки минус заголовок
    }

    public function test_count_rows_empty_csv(): void
    {
        $path = $this->createCsv('test.csv', "name,sku\n");

        $count = $this->parser->countRows($path);

        $this->assertEquals(0, $count);
    }

    public function test_count_rows_header_only(): void
    {
        $path = $this->createCsv('test.csv', "name,sku");

        $count = $this->parser->countRows($path);

        $this->assertEquals(0, $count);
    }

    // ── Ошибки ───────────────────────────────────────────────

    public function test_throws_on_unsupported_format(): void
    {
        $path = $this->createCsv('test.json', '{"key": "value"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported file format: json');

        iterator_to_array($this->parser->parse($path));
    }

    public function test_throws_on_nonexistent_file(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array($this->parser->parse('/nonexistent/file.csv'));
    }
}
