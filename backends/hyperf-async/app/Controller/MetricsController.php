<?php
declare(strict_types=1);
namespace App\Controller;

use App\Repository\MetricsRepository;
use App\Validation\RequestInput;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Swow\Psr7\Message\ResponsePlusInterface;

final class MetricsController extends AbstractController
{
    public function __construct(private MetricsRepository $metrics) {}

    public function index(RequestInterface $request, ResponseInterface $response): ResponsePlusInterface
    {
        RequestInput::query($request, []);
        return $this->json($response, $this->metrics->metrics());
    }
}
