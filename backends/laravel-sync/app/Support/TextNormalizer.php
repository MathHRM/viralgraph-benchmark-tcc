<?php

declare(strict_types=1);

namespace App\Support;

use Normalizer;

final class TextNormalizer
{
    public static function normalize(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            return $value;
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        return $normalized === false ? $value : $normalized;
    }
}
