<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRow>
 */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    public function definition(): array
    {
        return [
            'import_id' => Import::factory(),
            'row_number' => fake()->numberBetween(2, 10000),
            'original_data' => [
                'name' => fake()->word(),
                'sku' => '',
                'price' => 'invalid',
                'qty' => '-1',
            ],
            'errors' => [
                'sku' => ['Артикул обязателен'],
                'price' => ['Цена должна быть числом'],
            ],
        ];
    }
}
