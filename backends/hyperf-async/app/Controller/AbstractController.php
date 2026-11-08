<?php
declare(strict_types=1);
namespace App\Controller;

use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

abstract class AbstractController
{
    protected function json(ResponseInterface $response, array $body, int $status = 200, ?string $location = null): ResponsePlusInterface
    {
        $result = $response->json($body)->withStatus($status)->withHeader('X-Instance', gethostname());
        return $location ? $result->withHeader('Location', $location) : $result;
    }
}
