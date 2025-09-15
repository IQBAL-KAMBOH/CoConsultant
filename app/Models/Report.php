<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    protected $fillable = ['type', 'filters', 'format', 'path', 'user_id'];

    protected $casts = [
        'filters' => 'array',
    ];
}
