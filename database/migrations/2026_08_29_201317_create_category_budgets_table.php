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
            
            // 🎯 FIXED: Direct User ID link
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->string('category_name'); 
            $table->decimal('budget_limit', 10, 2); 
            $table->timestamps();
            
            // Ek user ek category ka ek hi budget bana sake
            $table->unique(['user_id', 'category_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_budgets');
    }
};