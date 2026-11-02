<?php

namespace App\Modules\Operations\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationService
{
    /** @param array<string, mixed> $resource
     * @return array{operation_id: string, status: string, resource: array<string, mixed>}
     */
    public function complete(int $userId, array $resource): array
    {
        $operationId = (string) Str::uuid();
        DB::table('operations')->insert([
            'id' => $operationId,
            'user_id' => $userId,
            'resource' => json_encode($resource, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['operation_id' => $operationId, 'status' => 'completed', 'resource' => $resource];
    }

    /** @return array{operation_id: string, status: string, resource?: array<string, mixed>}|null */
    public function find(string $operationId, int $userId): ?array
    {
        $operation = DB::table('operations')->where('id', $operationId)->where('user_id', $userId)->first();
        if (! $operation) {
            return null;
        }

        return ['operation_id' => $operation->id, 'status' => 'completed', 'resource' => json_decode($operation->resource, true, flags: JSON_THROW_ON_ERROR)];
    }
}
