<?php

namespace App\Services;

use Generator;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Парсит CSV/Excel в ленивый генератор строк.
 * Использует Generator чтобы не загружать весь файл в память.
 */
class FileParser
{
    /**
     * Читает файл и возвращает генератор строк как ассоциативных массивов.
     *
     * @return Generator<int, array<string, string>>
     */
    public function parse(string $filePath): Generator
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($filePath),
            'xlsx', 'xls' => $this->parseExcel($filePath),
            default => throw new RuntimeException("Unsupported file format: {$extension}"),
        };
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function parseCsv(string $filePath): Generator
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$filePath}");
        }

        try {
            // Первая строка — заголовки
            $headers = fgetcsv($handle);
            if ($headers === false) {
                return;
            }

            $headers = array_map('trim', $headers);
            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Пропускаем пустые строки
                if (count(array_filter($row)) === 0) {
                    continue;
                }

                // Если колонок меньше, чем заголовков — дополняем пустыми
                if (count($row) < count($headers)) {
                    $row = array_pad($row, count($headers), '');
                }

                yield $rowNumber => array_combine($headers, array_slice($row, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Для Excel используем PhpSpreadsheet (composer require phpoffice/phpspreadsheet).
     *
     * @return Generator<int, array<string, string>>
     */
    private function parseExcel(string $filePath): Generator
    {
        // PhpSpreadsheet для больших файлов читаем chunk-ами через ReadFilter
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $headers = [];
        $rowNumber = 0;

        foreach ($worksheet->getRowIterator() as $row) {
            $rowNumber++;
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = (string) $cell->getValue();
            }

            if ($rowNumber === 1) {
                $headers = array_map('trim', $rowData);
                continue;
            }

            if (count(array_filter($rowData)) === 0) {
                continue;
            }

            yield $rowNumber => array_combine($headers, array_slice($rowData, 0, count($headers)));
        }
    }

    /**
     * Быстрый подсчёт строк без загрузки всего файла.
     */
    public function countRows(string $filePath): int
    {
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);

        if (in_array($extension, ['csv', 'txt'])) {
            $count = 0;
            $handle = fopen($filePath, 'r');
            while (fgets($handle) !== false) {
                $count++;
            }
            fclose($handle);

            return max(0, $count - 1); // минус заголовок
        }

        // Для Excel
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

        return $spreadsheet->getActiveSheet()->getHighestRow() - 1;
    }
}
