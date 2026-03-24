<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Хранит ТОЛЬКО строки с ошибками для дебага.
        // Успешные строки попадают сразу в целевую таблицу (products, users и т.д.)
        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('original_data');   // оригинальные данные строки
            $table->json('errors');          // массив ошибок валидации
            $table->timestamps();

            $table->index('import_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
