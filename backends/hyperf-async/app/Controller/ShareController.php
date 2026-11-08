<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShareService;
use App\Validation\RequestInput;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class ShareController extends AbstractController
{
    public function __construct(private ShareService $service) {}

    public function create(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->service->create(RequestInput::body($request, ['usuario_id', 'post_id'])), 201);
    }
}
