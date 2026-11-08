<?php
declare(strict_types=1);
namespace App\Exception\Handler;

use App\Exception\ApiError;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Swow\Psr7\Message\ResponsePlusInterface;

/** Inclui falhas de parsing que acontecem antes de o roteador executar a API. */
final class HttpErrorHandler extends ExceptionHandler
{
    public function isValid(\Throwable $throwable): bool
    {
        return true;
    }

    public function handle(\Throwable $throwable, ResponsePlusInterface $response)
    {
        $status = $throwable instanceof ApiError ? $throwable->status
            : ($throwable instanceof HttpException ? $throwable->getStatusCode() : 503);
        [$code, $message] = match ($status) {
            400 => ['json_invalido', 'JSON malformado.'],
            404 => ['nao_encontrado', 'Recurso não encontrado.'],
            405 => ['metodo_invalido', 'Método não permitido.'],
            default => ['indisponivel', 'Serviço temporariamente indisponível.'],
        };
        if ($throwable instanceof ApiError) {
            [$code, $message] = [$throwable->codigo, $throwable->getMessage()];
        }
        if ($status >= 500) {
            error_log($throwable->getMessage());
        }
        $this->stopPropagation();
        return $response->setStatus($status)->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('X-Instance', gethostname())
            ->setBody(new SwooleStream(json_encode(['erro' => ['codigo' => $code, 'mensagem' => $message]], JSON_THROW_ON_ERROR)));
    }
}
