<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Repositories\FeedItemRepository;
use App\Repositories\UserRepository;
use App\Support\FeedCursorCodec;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FeedService
{
    public function __construct(
        private readonly FeedItemRepository $feedItems,
        private readonly UserRepository $users,
    ) {}

    /** @return array{data: list<array<string, mixed>>, proximo_cursor: ?string} */
    public function page(string $userId, ?string $cursor): array
    {
        if (! $this->users->find($userId)) {
            throw (new ModelNotFoundException)->setModel(User::class, [$userId]);
        }

        $scope = 'feed:'.$userId;
        $decoded = FeedCursorCodec::decode($cursor, $scope);
        $rows = $this->feedItems->page($userId, $decoded);
        $more = count($rows) > 50;
        $data = array_slice($rows, 0, 50);
        $last = $data ? $data[array_key_last($data)] : null;

        return [
            'data' => array_map(static function (array $row): array {
                unset($row['seq']);
                return $row;
            }, $data),
            'proximo_cursor' => $more && $last !== null
                ? FeedCursorCodec::encode($scope, $last['seq'], $last['post']['id'])
                : null,
        ];
    }
}
