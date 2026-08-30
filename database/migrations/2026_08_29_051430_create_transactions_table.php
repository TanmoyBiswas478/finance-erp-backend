<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable(); // Naya column
            $table->decimal('amount', 10, 2);
            $table->string('type'); // EXPENSE, INCOME ke liye
            $table->string('category')->nullable();
            $table->string('source')->nullable(); // 'Jupiter' jaise text ke liye
            $table->string('source_type')->nullable(); // 'ACCOUNT', 'CREDIT_CARD' ke liye
            $table->text('raw_sms')->nullable(); // Pura SMS save karne ke liye
            $table->dateTime('date')->nullable(); // Date aur time dono ke liye
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
