<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\ShareService;
use App\Request\CreateShareRequest;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class ShareController extends AbstractController
{
    public function __construct(private ShareService $service) {}

    public function create(CreateShareRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->service->create($request->validated()), 201);
    }
}
