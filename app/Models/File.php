<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class File extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'user_id',
        'name',
        'type',
        'path',
        'size'
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function permissions()
    {
        return $this->hasMany(FilePermission::class);
    }

    public function history()
    {
        return $this->hasMany(FileHistory::class);
    }

    public function parent()
    {
        return $this->belongsTo(File::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(File::class, 'parent_id');
    }
}
