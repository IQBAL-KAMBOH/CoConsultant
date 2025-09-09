<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileHistory extends Model
{
    protected $fillable = [
        'file_id', 'user_id', 'action', 'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function file()
    {
        return $this->belongsTo(File::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
