<?php

namespace App\Modules\Shares\Models;

use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'post_id', 'origin_share_id'])]
class Share extends Model
{
    /** @use HasFactory<ShareFactory> */
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return ShareFactory::new();
    }
}
