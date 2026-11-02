<?php

namespace App\Modules\Feed\Services;

use Illuminate\Support\Facades\DB;

class FeedService
{
    /** @return array{data: list<array<string, mixed>>, pagination: array{next_cursor: ?string}} */
    public function page(int $userId, int $limit, ?string $cursor): array
    {
        $followedIds = DB::table('follows')->where('follower_id', $userId)->select('followed_id');
        $authors = $followedIds->union(DB::query()->selectRaw('? as followed_id', [$userId]));

        $posts = DB::table('posts')->selectRaw("posts.id as item_id, 'post' as kind, posts.created_at as action_created_at, posts.id as post_id, posts.user_id as actor_id")
            ->whereIn('posts.user_id', $authors);
        $shares = DB::table('shares')->join('posts', 'posts.id', '=', 'shares.post_id')
            ->selectRaw("shares.id as item_id, 'share' as kind, shares.created_at as action_created_at, posts.id as post_id, shares.user_id as actor_id")
            ->whereIn('shares.user_id', $authors);
        $items = DB::query()->fromSub($posts->unionAll($shares), 'activity');

        if ($cursor !== null) {
            $decoded = $this->decodeCursor($cursor);
            if ($decoded === null) {
                return ['data' => [], 'pagination' => ['next_cursor' => null]];
            }
            $items->where(function ($query) use ($decoded): void {
                $query->where('action_created_at', '<', $decoded['created_at'])
                    ->orWhere(function ($query) use ($decoded): void {
                        $query->where('action_created_at', '=', $decoded['created_at'])->where('item_id', '<', $decoded['id']);
                    })
                    ->orWhere(function ($query) use ($decoded): void {
                        $query->where('action_created_at', '=', $decoded['created_at'])->where('item_id', '=', $decoded['id'])->where('kind', '<', $decoded['kind']);
                    });
            });
        }

        $rows = $items->orderByDesc('action_created_at')->orderByDesc('item_id')->orderByDesc('kind')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);
        $postIds = $page->pluck('post_id')->unique();
        $originals = DB::table('posts')->whereIn('id', $postIds)->get(['id', 'content', 'user_id', 'created_at'])->keyBy('id');
        $authorIds = $originals->pluck('user_id');
        $users = DB::table('users')->whereIn('id', $page->pluck('actor_id')->merge($authorIds)->unique())->get(['id', 'username'])->keyBy('id');
        $data = $page->map(function (object $row) use ($users, $originals): array {
            $post = $originals[$row->post_id];
            $author = $users[$post->user_id];
            $actor = $users[$row->actor_id];

            return [
                'id' => (int) $row->item_id,
                'kind' => $row->kind,
                'created_at' => $row->action_created_at,
                'actor' => ['id' => (int) $actor->id, 'username' => $actor->username],
                'post' => [
                    'id' => (int) $post->id,
                    'content' => $post->content,
                    'author' => ['id' => (int) $author->id, 'username' => $author->username],
                    'created_at' => $post->created_at,
                ],
            ];
        })->values()->all();
        $last = $page->last();

        return [
            'data' => $data,
            'pagination' => ['next_cursor' => $hasMore && $last ? $this->encodeCursor($last) : null],
        ];
    }

    /** @return array{created_at: string, id: int, kind: string}|null */
    private function decodeCursor(string $cursor): ?array
    {
        $value = json_decode(base64_decode($cursor, true) ?: '', true);

        return is_array($value) && isset($value['created_at'], $value['id'], $value['kind']) ? $value : null;
    }

    private function encodeCursor(object $row): string
    {
        return base64_encode(json_encode(['created_at' => $row->action_created_at, 'id' => (int) $row->item_id, 'kind' => $row->kind], JSON_THROW_ON_ERROR));
    }
}
