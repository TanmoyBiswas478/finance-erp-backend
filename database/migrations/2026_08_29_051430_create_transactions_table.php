<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            
            // 🎯 LINKED: User relationship
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            
            $table->string('title')->nullable(); 
            $table->decimal('amount', 10, 2);
            
            // 🎯 FIXED: ENUM strict typing directly upon creation
            $table->enum('type', ['DEBIT', 'CREDIT', 'TRANSFER', 'CC_BILL', 'EXPENSE', 'INCOME', 'STATEMENT'])->default('EXPENSE'); 
            
            $table->string('category')->nullable();
            $table->string('source')->nullable(); 
            $table->string('source_type')->nullable(); 
            
            // 🎯 INTEGRATED: Transfer Target Fields natively
            $table->string('transfer_target_type')->nullable(); 
            $table->unsignedBigInteger('transfer_target_id')->nullable();
            
            $table->text('raw_sms')->nullable(); 
            $table->dateTime('date')->nullable(); 
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};