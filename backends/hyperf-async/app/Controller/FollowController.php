<?php
declare(strict_types=1);
namespace App\Controller;

use App\Service\FollowService;
use App\Validation\RequestInput;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class FollowController extends AbstractController
{
    public function __construct(private FollowService $service) {}

    public function create(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->service->create(RequestInput::body($request, ['seguidor_id', 'seguido_id'])), 201);
    }
}
