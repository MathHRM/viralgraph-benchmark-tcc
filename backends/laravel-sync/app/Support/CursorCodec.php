<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CursorCodec
{
    public static function encode(string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['id' => $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }

        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $payload = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($payload) || ! isset($payload['id']) || ! is_string($payload['id']) || ! Str::isUuid($payload['id'])) {
            throw ValidationException::withMessages(['cursor' => ['O cursor informado é inválido.']]);
        }

        return $payload['id'];
    }
}
