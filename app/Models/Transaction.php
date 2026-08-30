<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    // Yeh line add karni hai (Iska matlab hai saare columns fillable hain)
    protected $guarded = []; 
}