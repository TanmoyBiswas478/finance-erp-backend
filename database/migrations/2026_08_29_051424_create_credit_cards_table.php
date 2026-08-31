<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            
            // 🎯 LINKED: User relationship setup
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->string('card_name');
            $table->decimal('total_limit', 10, 2)->default(0.00);
            $table->decimal('available_limit', 10, 2)->default(0.00);
            $table->decimal('billed_outstanding', 10, 2)->default(0.00);
            $table->decimal('unbilled_outstanding', 10, 2)->default(0.00);
            $table->integer('billing_date')->nullable();
            $table->boolean('is_upi_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('credit_cards');
    }
};