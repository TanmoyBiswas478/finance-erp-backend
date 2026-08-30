<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_budgets', function (Blueprint $table) {
            $table->id();
            $table->string('category_name')->unique(); // Kis category ka budget hai
            $table->decimal('budget_limit', 10, 2); // Maximum limit
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_budgets');
    }
};