<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BenchmarkCorpusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('BENCHMARK_MODE');
    }

    public function test_command_loads_a_verified_corpus_in_dependency_order(): void
    {
        putenv('BENCHMARK_MODE=1');
        $directory = $this->writeCorpus();
        $resultPath = dirname($directory).'/load-sync-'.basename($directory).'.json';
        $output = new BufferedOutput();

        $status = Artisan::call('benchmark:load-corpus', [
            'directory' => $directory,
            '--result' => $resultPath,
        ], $output);

        $this->assertSame(0, $status, Artisan::output());
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('posts', 30);
        $this->assertDatabaseCount('follows', 30);
        $this->assertDatabaseCount('shares', 30);
        $this->assertDatabaseCount('feed_items', 150);
        $this->assertDatabaseHas('users', ['feed_seq' => 25]);
        $this->assertDatabaseHas('feed_items', ['seq' => 25]);

        $result = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame(150, $result['counts']['feed_items']);
        $this->assertArrayHasKey('duration_ms', $result);
    }

    public function test_command_rejects_corrupt_corpus_before_writing(): void
    {
        putenv('BENCHMARK_MODE=1');
        $directory = $this->writeCorpus();
        file_put_contents($directory.'/posts.csv', "corrupt\n");

        $status = Artisan::call('benchmark:load-corpus', ['directory' => $directory]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('SHA-256', Artisan::output());
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_command_refuses_to_load_outside_benchmark_mode(): void
    {
        putenv('BENCHMARK_MODE');
        $directory = $this->writeCorpus();

        $status = Artisan::call('benchmark:load-corpus', ['directory' => $directory]);

        $this->assertSame(1, $status);
        $this->assertDatabaseCount('users', 0);
    }

    private function writeCorpus(): string
    {
        $directory = sys_get_temp_dir().'/benchmark-corpus-'.Str::uuid();
        mkdir($directory, 0700, true);
        $users = [];
        $posts = [];
        $follows = [];
        $shares = [];
        $feedItems = [];
        $ids = [];
        $createdAt = '2026-11-08 00:00:00';

        for ($user = 0; $user < 6; $user++) {
            $ids[$user] = (string) Str::uuid();
            $users[] = [$ids[$user], 'Pessoa '.$user, 'seed-u'.$user, '2000-01-15',
                $createdAt, $createdAt, 25];
        }
        for ($post = 0; $post < 30; $post++) {
            $author = $post % 6;
            $posts[] = [(string) Str::uuid(), $ids[$author], 'viral seed post '.$post, $createdAt];
        }
        foreach ($ids as $followerIndex => $followerId) {
            foreach ($ids as $followedIndex => $followedId) {
                if ($followerIndex !== $followedIndex) {
                    $follows[] = [(string) Str::uuid(), $followerId, $followedId, $createdAt];
                }
            }
            $sequence = 0;
            foreach ($posts as $post) {
                if ($post[1] !== $followerId) {
                    $sequence++;
                    $feedItems[] = [(string) Str::uuid(), $followerId, $post[0], 'post',
                        $post[1], $post[0], $sequence];
                    if ($sequence <= 5) {
                        $shares[] = [(string) Str::uuid(), $followerId, $post[0], $createdAt];
                    }
                }
            }
        }

        $files = [
            'users.csv' => [['id', 'name', 'nickname', 'birth_date', 'created_at', 'updated_at', 'feed_seq'], $users],
            'posts.csv' => [['id', 'user_id', 'content', 'created_at'], $posts],
            'follows.csv' => [['id', 'follower_id', 'followed_id', 'created_at'], $follows],
            'shares.csv' => [['id', 'user_id', 'post_id', 'created_at'], $shares],
            'feed_items.csv' => [['id', 'user_id', 'post_id', 'origin', 'event_author_id', 'event_id', 'seq'], $feedItems],
        ];
        $counts = [];
        $fileManifest = [];
        foreach ($files as $name => [$header, $rows]) {
            $stream = fopen($directory.'/'.$name, 'w');
            fputcsv($stream, $header);
            foreach ($rows as $row) {
                fputcsv($stream, $row);
            }
            fclose($stream);
            $key = pathinfo($name, PATHINFO_FILENAME);
            $counts[$key] = count($rows);
            $fileManifest[$name] = [
                'bytes' => filesize($directory.'/'.$name),
                'sha256' => hash_file('sha256', $directory.'/'.$name),
            ];
        }
        file_put_contents($directory.'/manifest.json', json_encode([
            'schema_version' => 1,
            'spec' => ['feed_limit' => 250, 'batch_size' => 4],
            'seed' => 7,
            'counts' => $counts,
            'files' => $fileManifest,
        ], JSON_THROW_ON_ERROR));

        return $directory;
    }
}
