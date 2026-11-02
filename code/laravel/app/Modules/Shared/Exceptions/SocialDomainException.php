<?php

namespace App\Modules\Shared\Exceptions;

use RuntimeException;

class SocialDomainException extends RuntimeException
{
    /** @param array<string, list<string>> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
