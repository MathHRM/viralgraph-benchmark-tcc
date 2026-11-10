<?php
declare(strict_types=1);
namespace App\Controller;

use App\Repository\MetricsRepository;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class MetricsController extends AbstractController
{
    public function __construct(private MetricsRepository $metrics) {}

    public function index(ResponseInterface $response): ResponsePlusInterface
    {
        return $this->json($response, $this->metrics->metrics());
    }
}
