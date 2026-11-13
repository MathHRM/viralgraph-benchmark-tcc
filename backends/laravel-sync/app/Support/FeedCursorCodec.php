<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

final class FeedCursorCodec
{
    public static function encode(string $scope, int $seq, string $postId): string
    {
        return base64_encode(json_encode([$scope, $seq, $postId], JSON_THROW_ON_ERROR));
    }

    /** @return array{0: string, 1: int, 2: string}|null */
    public static function decode(?string $cursor, string $scope): ?array
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $decoded = base64_decode($cursor, true);
            $parts = $decoded === false ? null : json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $parts = null;
        }

        if (! is_array($parts) || ! array_is_list($parts) || count($parts) !== 3
            || $parts[0] !== $scope || ! is_int($parts[1]) || $parts[1] < 1
            || ! is_string($parts[2]) || ! Str::isUuid($parts[2])) {
            throw ValidationException::withMessages(['cursor' => ['O cursor informado é inválido.']]);
        }

        return $parts;
    }
}
