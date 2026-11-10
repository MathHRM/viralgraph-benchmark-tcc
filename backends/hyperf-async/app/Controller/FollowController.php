<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\FollowService;
use App\Request\CreateFollowRequest;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class FollowController extends AbstractController
{
    public function __construct(private FollowService $service) {}

    public function create(CreateFollowRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->service->create($request->validated()), 201);
    }
}
