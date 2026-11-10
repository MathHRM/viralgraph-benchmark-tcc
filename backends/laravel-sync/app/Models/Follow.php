<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Follow extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'follower_id',
        'followed_id',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
