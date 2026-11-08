<?php
declare(strict_types=1);
namespace App\Controller;

use App\Pagination\Page;
use App\Repository\UserRepository;
use App\Service\UserService;
use App\Validation\Input;
use App\Validation\RequestInput;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class UserController extends AbstractController
{
    public function __construct(private UserService $service, private UserRepository $repository) {}

    public function create(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        $values = RequestInput::body($request, ['nome', 'apelido', 'nascimento']);
        $values['nome'] = Input::text($values['nome'], 32, true);
        $values['apelido'] = Input::text($values['apelido'], 100, true);
        $user = $this->service->create($values);
        return $this->json($response, $user, 201, '/usuarios/'.$user['id']);
    }

    public function show(string $id, RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        RequestInput::query($request, []);
        return $this->json($response, $this->repository->get(Input::uuid($id)));
    }

    public function search(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        $query = RequestInput::query($request, ['s', 'cursor']);
        $term = Input::text($query['s'] ?? null, 100);
        $scope = '/usuarios:'.$term;
        $cursor = Input::cursor($query['cursor'] ?? null, $scope);
        return $this->json($response, Page::fromRows($this->repository->search($term, $cursor), $scope));
    }
}
