<?php

namespace Database\Factories;

use App\Modules\Shares\Models\Share;
use App\Modules\Posts\Models\Post;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Share>
 */
class ShareFactory extends Factory
{
    protected $model = Share::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'post_id' => Post::factory(),
            'origin_share_id' => null,
        ];
    }
}
