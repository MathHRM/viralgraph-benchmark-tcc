<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchPostsRequest;
use App\Http\Requests\ShowPostRequest;
use App\Http\Requests\StorePostRequest;
use App\Services\PostService;
use Illuminate\Http\JsonResponse;

class PostController extends Controller
{
    public function __construct(
        private readonly PostService $service,
    ) {}

    public function search(SearchPostsRequest $request): JsonResponse
    {
        return response()->json($this->service->search(
            $request->string('c')->toString(),
            $request->input('cursor'),
        ));
    }

    public function create(StorePostRequest $request): JsonResponse
    {
        $post = $this->service->create(
            $request->string('usuario_id')->toString(),
            $request->string('conteudo')->toString(),
        );

        return response()->json(PostService::toApi($post), 201)
            ->header('Location', '/posts/'.$post->id);
    }

    public function show(ShowPostRequest $request, string $id): JsonResponse
    {
        return response()->json(PostService::toApi($this->service->show($id)));
    }
}
