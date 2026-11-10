<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FeedItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'post_id',
        'origin',
        'event_author_id',
        'event_id',
    ];

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }
}
