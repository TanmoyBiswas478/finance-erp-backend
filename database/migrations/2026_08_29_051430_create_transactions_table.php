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
        $table->decimal('amount', 10, 2);
        $table->enum('transaction_type', ['CREDIT', 'DEBIT']);
        $table->string('category');
        $table->unsignedBigInteger('source_id');
        $table->enum('source_type', ['ACCOUNT', 'CREDIT_CARD']);
        $table->date('transaction_date');
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
