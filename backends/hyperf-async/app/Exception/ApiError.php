<?php
declare(strict_types=1);
namespace App\Exception;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status = 422, public readonly string $codigo = 'validacao', string $message = 'Dados inválidos.')
    {
        parent::__construct($message);
    }
}
