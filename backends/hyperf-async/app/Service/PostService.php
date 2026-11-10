<?php
declare(strict_types=1);
namespace App\Service;

use App\Infrastructure\RabbitMQ\BrokerFactory;
use App\Repository\UserRepository;
use App\Validation\Input;

final class PostService
{
    public function __construct(private UserRepository $users, private BrokerFactory $brokers) {}

    public function create(array $values): string
    {
        $this->users->get($values['usuario_id']);
        $id = Input::id();
        $broker = $this->brokers->open();
        try {
            $broker->publish('posts', ['id' => $id] + $values + ['criado_em' => Input::now()]);
        } finally {
            $broker->close();
        }
        return $id;
    }
}
