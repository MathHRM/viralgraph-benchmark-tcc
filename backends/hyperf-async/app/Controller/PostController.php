<?php
declare(strict_types=1);
namespace App\Controller;

use App\Pagination\Page;
use App\Repository\PostRepository;
use App\Service\PostService;
use App\Validation\Input;
use App\Validation\RequestInput;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class PostController extends AbstractController
{
    public function __construct(private PostService $service, private PostRepository $repository) {}

    public function create(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        $values = RequestInput::body($request, ['usuario_id', 'conteudo']);
        $values['conteudo'] = Input::text($values['conteudo'], 500);
        $id = $this->service->create($values);
        return $this->json($response, ['id' => $id], 201, '/posts/'.$id);
    }

    public function show(string $id, RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        RequestInput::query($request, []);
        return $this->json($response, $this->repository->get(Input::uuid($id)));
    }

    public function search(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        $query = RequestInput::query($request, ['c', 'cursor']);
        $term = Input::text($query['c'] ?? null, 500);
        $scope = '/posts:'.$term;
        $cursor = Input::cursor($query['cursor'] ?? null, $scope);
        return $this->json($response, Page::fromRows($this->repository->search($term, $cursor), $scope));
    }
}
