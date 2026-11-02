<?php

namespace Tests\Feature;

use App\Modules\Posts\Models\Post;
use App\Modules\Shares\Models\Share;
use App\Modules\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_usernames_are_required_and_unique_without_case_sensitivity(): void
    {
        $this->postJson('/api/users', ['username' => 'Maria'])
            ->assertCreated()
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.username', 'Maria');

        $this->postJson('/api/users', ['username' => 'maria'])
            ->assertStatus(409)
            ->assertJsonValidationErrors('username');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_follows_are_directed_unique_and_reject_self_follow(): void
    {
        $follower = User::factory()->create();
        $followed = User::factory()->create();
        $payload = ['follower_id' => $follower->id, 'followed_id' => $followed->id];

        $this->postJson('/api/follows', $payload)->assertCreated();
        $this->postJson('/api/follows', $payload)->assertOk();
        $this->postJson('/api/follows', [
            'follower_id' => $follower->id,
            'followed_id' => $follower->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseHas('follows', $payload);
    }

    public function test_posts_accept_non_whitespace_text_up_to_500_characters(): void
    {
        $author = User::factory()->create();

        $this->postJson('/api/posts', ['user_id' => $author->id, 'content' => str_repeat('a', 500)])
            ->assertCreated();
        $this->postJson('/api/posts', ['user_id' => $author->id, 'content' => " \t\n"])
            ->assertUnprocessable();
        $this->postJson('/api/posts', ['user_id' => $author->id, 'content' => str_repeat('a', 501)])
            ->assertUnprocessable();

        $this->assertDatabaseCount('posts', 1);
    }

    public function test_shares_are_unique_preserve_origin_and_reject_own_post(): void
    {
        $author = User::factory()->create();
        $firstSharer = User::factory()->create();
        $secondSharer = User::factory()->create();
        $post = Post::factory()->create(['user_id' => $author->id]);

        $firstShareResponse = $this->postJson('/api/shares', [
            'user_id' => $firstSharer->id,
            'post_id' => $post->id,
        ])->assertCreated();
        $firstShareId = $firstShareResponse->json('data.id');

        $this->postJson('/api/shares', [
            'user_id' => $secondSharer->id,
            'post_id' => $post->id,
            'origin_share_id' => $firstShareId,
        ])->assertCreated()->assertJsonPath('data.origin_share_id', $firstShareId);

        $this->postJson('/api/shares', [
            'user_id' => $firstSharer->id,
            'post_id' => $post->id,
        ])->assertOk();
        $this->postJson('/api/shares', [
            'user_id' => $author->id,
            'post_id' => $post->id,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('shares', 2);
        $this->assertSame(1, Post::count());
    }

    public function test_missing_references_and_cross_post_share_origins_are_rejected(): void
    {
        $author = User::factory()->create();
        $sharer = User::factory()->create();
        $firstPost = Post::factory()->create(['user_id' => $author->id]);
        $secondPost = Post::factory()->create(['user_id' => $author->id]);
        $origin = Share::factory()->create(['user_id' => $sharer->id, 'post_id' => $firstPost->id]);

        $this->postJson('/api/posts', ['user_id' => 9999, 'content' => 'Texto'])
            ->assertNotFound();
        $this->postJson('/api/shares', [
            'user_id' => $sharer->id,
            'post_id' => $secondPost->id,
            'origin_share_id' => $origin->id,
        ])->assertUnprocessable();
        $this->postJson('/api/follows', ['follower_id' => 9999, 'followed_id' => $author->id])
            ->assertNotFound();
    }
}
