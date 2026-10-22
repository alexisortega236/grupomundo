<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class TransactionSideEffects
{
    /** @var array<string, true> */
    private array $stagedPaths = [];

    /** @var array<string, true> */
    private array $deleteAfterCommit = [];

    private string $operationId;

    public function __construct()
    {
        $this->operationId = bin2hex(random_bytes(16));
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function stage(string $path): void
    {
        $this->stagedPaths[$path] = true;
    }

    /** @param array<int, string> $paths */
    public function deleteAfterCommit(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path !== '') {
                $this->deleteAfterCommit[$path] = true;
            }
        }
    }

    public function committed(): void
    {
        foreach (array_keys($this->deleteAfterCommit) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $exception) {
                Log::error('Deferred property media deletion failed.', [
                    'path_hash' => hash('sha256', $path),
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->stagedPaths = [];
        $this->deleteAfterCommit = [];
    }

    public function rolledBack(): void
    {
        foreach (array_keys($this->stagedPaths) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $exception) {
                Log::error('Rolled-back property media cleanup failed.', [
                    'path_hash' => hash('sha256', $path),
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->stagedPaths = [];
        $this->deleteAfterCommit = [];
    }
}
