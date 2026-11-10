<?php
declare(strict_types=1);
namespace App\Pagination;

final class Page
{
    public static function fromRows(array $rows, string $scope, bool $feed = false): array
    {
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $last = $rows ? $rows[count($rows) - 1] : null;
        $next = $more ? base64_encode(json_encode([$scope, $feed ? $last['seq'] : 0, $feed ? $last['post']['id'] : $last['item']['id']], JSON_THROW_ON_ERROR)) : null;
        $data = array_map(function ($r) use ($feed) {
            if (!$feed) {
                return $r['item'];
            }
            unset($r['seq']);
            return $r;
        }, $rows);
        return ['data' => $data, 'proximo_cursor' => $next];
    }
}
