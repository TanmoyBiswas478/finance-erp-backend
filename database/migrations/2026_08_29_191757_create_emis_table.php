<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emis', function (Blueprint $table) {
            $table->id();
            $table->string('emi_name'); 
            $table->decimal('emi_amount', 10, 2);
            $table->string('source_type'); 
            $table->unsignedBigInteger('source_id'); 
            $table->integer('total_installments');
            $table->integer('paid_installments')->default(0);
            $table->integer('deduction_date'); 
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emis');
    }
};