<?php
declare(strict_types=1);
namespace App\Controller;

use App\Pagination\Page;
use App\Repository\UserRepository;
use App\Service\UserService;
use App\Validation\Input;
use App\Request\CreateUserRequest;
use App\Request\SearchUserRequest;
use App\Request\ResourceRequest;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class UserController extends AbstractController
{
    public function __construct(private UserService $service, private UserRepository $repository) {}

    public function create(CreateUserRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        $values = $request->validated();
        $user = $this->service->create($values);
        return $this->json($response, $user, 201, '/usuarios/'.$user['id']);
    }

    public function show(ResourceRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->repository->get($request->validated()['id']));
    }

    public function search(SearchUserRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        $query = $request->validated();
        $term = $query['s'];
        $scope = '/usuarios:'.$term;
        $cursor = Input::cursor($query['cursor'] ?? null, $scope);
        return $this->json($response, Page::fromRows($this->repository->search($term, $cursor), $scope));
    }
}
