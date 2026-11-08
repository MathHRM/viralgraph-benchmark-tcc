<?php
declare(strict_types=1);
namespace App\Validation;

use App\Exception\ApiError;

final class Input
{
    public static function uuid(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id)) {
            throw new ApiError();
        }
        return strtolower($id);
    }

    public static function id(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 15) | 64);
        $b[8] = chr((ord($b[8]) & 63) | 128);
        $s = bin2hex($b);
        return substr($s, 0, 8).'-'.substr($s, 8, 4).'-'.substr($s, 12, 4).'-'.substr($s, 16, 4).'-'.substr($s, 20);
    }

    public static function normalize(string $value, bool $trim = false): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new ApiError();
        }
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);
        return $trim ? preg_replace('/^\s+|\s+$/u', '', $value) : $value;
    }

    public static function body(string $raw, string $type): array
    {
        if (strtolower(trim(explode(';', $type)[0])) !== 'application/json') {
            throw new ApiError(415, 'tipo_midia', 'Envie application/json.');
        }
        try {
            $v = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'json_invalido', 'JSON malformado.');
        }
        if (!$v instanceof \stdClass) {
            throw new ApiError();
        }
        return (array) $v;
    }

    public static function birth(mixed $v, string $reference): string
    {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v)) {
            throw new ApiError();
        }
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        if (!checkdate($m, $d, $y)) {
            throw new ApiError();
        }
        $anniversary = sprintf('%04d-%02d-%02d', $y + 18, $m, $d);
        if (!checkdate($m, $d, $y + 18)) {
            $anniversary = sprintf('%04d-03-01', $y + 18);
        }
        if ($anniversary > $reference || $v > $reference) {
            throw new ApiError();
        }
        return $v;
    }

    public static function cursor(?string $value, string $scope): ?array
    {
        if ($value === null) {
            return null;
        }
        try {
            $parts = json_decode(base64_decode($value, true) ?: '', true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($parts) || !array_is_list($parts) || count($parts) !== 3 || $parts[0] !== $scope || !is_int($parts[1]) || $parts[1] < 0 || !is_string($parts[2])) {
                throw new ApiError();
            }
            self::uuid($parts[2]);
            return $parts;
        } catch (\JsonException) {
            throw new ApiError();
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
