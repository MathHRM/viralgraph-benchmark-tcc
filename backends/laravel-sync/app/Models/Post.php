<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Post extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'content',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
