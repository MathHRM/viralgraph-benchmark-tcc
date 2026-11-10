<?php
declare(strict_types=1);
namespace App\Controller;

use App\Pagination\Page;
use App\Repository\PostRepository;
use App\Service\PostService;
use App\Validation\Input;
use App\Request\CreatePostRequest;
use App\Request\SearchPostRequest;
use App\Request\ResourceRequest;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class PostController extends AbstractController
{
    public function __construct(private PostService $service, private PostRepository $repository) {}

    public function create(CreatePostRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        $values = $request->validated();
        $id = $this->service->create($values);
        return $this->json($response, ['id' => $id], 201, '/posts/'.$id);
    }

    public function show(ResourceRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->repository->get($request->validated()['id']));
    }

    public function search(SearchPostRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        $query = $request->validated();
        $term = $query['c'];
        $scope = '/posts:'.$term;
        $cursor = Input::cursor($query['cursor'] ?? null, $scope);
        return $this->json($response, Page::fromRows($this->repository->search($term, $cursor), $scope));
    }
}
