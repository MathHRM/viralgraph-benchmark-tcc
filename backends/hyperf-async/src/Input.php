<?php
declare(strict_types=1);
namespace App;

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

    public static function text(mixed $v, int $max, bool $trim = false): string
    {
        if (!is_string($v) || !mb_check_encoding($v, 'UTF-8')) {
            throw new ApiError();
        }
        $v = \Normalizer::normalize($v, \Normalizer::FORM_C);
        if ($trim) {
            $v = preg_replace('/^\s+|\s+$/u', '', $v);
        }
        if (!preg_match('/\S/u', $v) || mb_strlen($v, 'UTF-8') > $max) {
            throw new ApiError();
        }
        return $v;
    }

    public static function body(string $raw, string $type, array $fields): array
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
        $v = (array) $v;
        if (array_diff(array_keys($v), $fields) || array_diff($fields, array_keys($v))) {
            throw new ApiError();
        }
        foreach ($v as $k => $value) {
            if (str_ends_with($k, '_id')) {
                if (!is_string($value)) {
                    throw new ApiError();
                }
                $v[$k] = self::uuid($value);
            }
        }
        return $v;
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
