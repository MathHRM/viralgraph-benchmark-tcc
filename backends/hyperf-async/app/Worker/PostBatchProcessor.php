<?php
declare(strict_types=1);
namespace App\Worker;

use App\Repository\PostRepository;

final class PostBatchProcessor
{
    public function __construct(private PostRepository $posts) {}

    public function process(array $messages): void
    {
        $this->posts->persistPosts(array_map(fn ($message) => json_decode($message->body, true, 512, JSON_THROW_ON_ERROR), $messages));
        foreach ($messages as $message) {
            $message->ack();
        }
    }
}
