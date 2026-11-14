<?php
declare(strict_types=1);
use App\Controller\{UserController, FollowController, PostController, ShareController, FeedController, MetricsController, NotFoundController};
use Hyperf\HttpServer\Router\Router;

Router::get('/usuarios', [UserController::class, 'search']);
Router::post('/usuarios', [UserController::class, 'create']);
Router::get('/usuarios/{id}', [UserController::class, 'show']);
Router::post('/seguir-usuario', [FollowController::class, 'create']);
Router::get('/posts', [PostController::class, 'search']);
Router::post('/posts', [PostController::class, 'create']);
Router::get('/posts/{id}', [PostController::class, 'show']);
Router::post('/posts/{id}/share', [ShareController::class, 'create']);
Router::get('/posts/{id}/propagation-tree', [ShareController::class, 'propagationTree']);
Router::get('/feed/{id}', [FeedController::class, 'index']);
Router::get('/metricas', [MetricsController::class, 'index']);

// Preserva o 404 histórico destas combinações; outros métodos continuam retornando 405.
foreach (['/seguir-usuario', '/posts/{id}/share'] as $path) {
    Router::get($path, [NotFoundController::class, 'index']);
}
foreach (['/usuarios/{id}', '/posts/{id}', '/feed/{id}', '/metricas'] as $path) {
    Router::post($path, [NotFoundController::class, 'index']);
}
