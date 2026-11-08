<?php
declare(strict_types=1);
use Hyperf\HttpServer\Router\Router;

$handler = function (\Hyperf\HttpServer\Contract\RequestInterface $request, \Hyperf\HttpServer\Contract\ResponseInterface $response) {
    try {
        [$status, $body, $location] = (new App\Api())->handle(
            $request->getMethod(), $request->getUri()->getPath(), $request->getQueryParams(),
            (string) $request->getBody(), $request->getHeaderLine('Content-Type')
        );
    } catch (App\ApiError $e) {
        $status = $e->status;
        $body = ['erro' => ['codigo' => $e->codigo, 'mensagem' => $e->getMessage()]];
        $location = null;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $status = 503;
        $body = ['erro' => ['codigo' => 'indisponivel', 'mensagem' => 'Serviço temporariamente indisponível.']];
        $location = null;
    }
    $result = $response->json($body)->withStatus($status)->withHeader('X-Instance', gethostname());
    return $location ? $result->withHeader('Location', $location) : $result;
};
foreach (['/usuarios', '/posts', '/seguir-usuario', '/compartilhar-post', '/metricas', '/usuarios/{id}', '/posts/{id}', '/feed/{id}'] as $path) {
    Router::addRoute(['GET', 'POST'], $path, $handler);
}
