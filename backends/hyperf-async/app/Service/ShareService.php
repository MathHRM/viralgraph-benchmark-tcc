<?php
declare(strict_types=1);
namespace App\Service;

use App\Exception\ApiError;
use App\Repository\PostRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Validation\Input;

final class ShareService
{
    public function __construct(private UserRepository $users, private PostRepository $posts, private ShareRepository $shares) {}

    public function create(array $values): array
    {
        $this->users->get($values['usuario_id']);
        $post = $this->posts->get($values['post_id']);
        if ($post['usuario_id'] === $values['usuario_id']) {
            throw new ApiError();
        }
        $result = ['id' => Input::id()] + $values + ['criado_em' => Input::now()];
        if (!$this->shares->create($result)) {
            throw new ApiError();
        }
        return $result;
    }
}
