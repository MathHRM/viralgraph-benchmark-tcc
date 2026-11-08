<?php
declare(strict_types=1);
namespace App\Validation;

use App\Exception\ApiError;
use Hyperf\Stringable\Str;

final class Input
{
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

    /** Recebe uma data já validada pela regra date_format do FormRequest. */
    public static function birth(string $v, string $reference): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        $anniversary = sprintf('%04d-%02d-%02d', $y + 18, $m, $d);
        if (!checkdate($m, $d, $y + 18)) {
            $anniversary = sprintf('%04d-03-01', $y + 18);
        }
        if ($anniversary > $reference) {
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
            if (!Str::isUuid($parts[2])) {
                throw new ApiError();
            }
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
