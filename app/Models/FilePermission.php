<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FilePermission extends Model
{
    protected $fillable = [
        'file_id', 'user_id', 'permission'
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

