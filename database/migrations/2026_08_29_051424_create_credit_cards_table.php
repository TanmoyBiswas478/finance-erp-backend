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
    Schema::create('credit_cards', function (Blueprint $table) {
        $table->id();
        $table->string('card_name');
        $table->decimal('total_limit', 10, 2);
        $table->decimal('available_limit', 10, 2);
        $table->integer('billing_date');
        $table->boolean('is_upi_enabled')->default(false);
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
