<?php
declare(strict_types=1);
namespace App\Controller;

use App\Request\CreateShareRequest;
use App\Request\ResourceRequest;
use App\Service\ShareService;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class ShareController extends AbstractController
{
    public function __construct(private ShareService $service) {}

    /** 202: evento confirmado pelo RabbitMQ; persistência no Neo4j ocorre no consumer. */
    public function create(CreateShareRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->service->accept($request->validated()), 202);
    }

    public function propagationTree(ResourceRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->service->tree($request->validated()['id']));
    }
}
