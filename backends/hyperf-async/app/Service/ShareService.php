<?php
declare(strict_types=1);
namespace App\Service;

use App\Exception\ApiError;
use App\Repository\PostRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Validation\Input;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Coroutine\Exception\ParallelExecutionException;
use Hyperf\Coroutine\Parallel;

final class ShareService
{
    public function __construct(private UserRepository $users, private PostRepository $posts, private ShareRepository $shares) {}

    public function create(array $values): array
    {
        // Existência do usuário e do post são validações independentes. Dentro de corrotina rodam
        // em paralelo (latência = máx, não soma dos dois round-trips); fora de corrotina seguem a
        // ordem sequencial original. A precedência do erro é preservada: se ambos faltarem, o 404
        // do usuário prevalece. A regra do próprio post e a unicidade seguem inalteradas.
        $post = $this->validate($values['usuario_id'], $values['post_id']);
        if ($post['usuario_id'] === $values['usuario_id']) {
            throw new ApiError();
        }
        $result = ['id' => Input::id()] + $values + ['criado_em' => Input::now()];
        if (!$this->shares->create($result)) {
            throw new ApiError();
        }
        return $result;
    }

    /**
     * Valida usuário e post; devolve as propriedades do post para a regra do próprio autor.
     * Preserva a semântica: 404 do usuário tem precedência sobre o 404 do post.
     */
    private function validate(string $userId, string $postId): array
    {
        if (!Coroutine::inCoroutine()) {
            $this->users->get($userId);
            return $this->posts->get($postId);
        }

        $parallel = new Parallel();
        $parallel->add(fn () => $this->users->get($userId), 'user');
        $parallel->add(fn () => $this->posts->get($postId), 'post');
        try {
            return $parallel->wait()['post'];
        } catch (ParallelExecutionException $failure) {
            // Reordena o erro conforme a precedência original: usuário antes do post.
            $throwables = $failure->getThrowables();
            throw $throwables['user'] ?? $throwables['post'];
        }
    }
}
