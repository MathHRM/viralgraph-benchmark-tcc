<?php
declare(strict_types=1);
namespace App\Controller;

use App\Pagination\Page;
use App\Repository\FeedRepository;
use App\Repository\UserRepository;
use App\Validation\Input;
use App\Request\FeedRequest;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class FeedController extends AbstractController
{
    public function __construct(private UserRepository $users, private FeedRepository $feeds) {}

    public function index(string $id, FeedRequest $request, ResponseInterface $response): ResponsePlusInterface
    {
        $query = $request->validated();
        $id = $query['id'];
        $scope = 'feed:'.$id;
        $cursor = Input::cursor($query['cursor'] ?? null, $scope);
        $this->users->get($id);
        return $this->json($response, Page::fromRows($this->feeds->page($id, $cursor), $scope, true));
    }
}
