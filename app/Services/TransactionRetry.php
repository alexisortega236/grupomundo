<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Closure;
use Throwable;

class TransactionRetry
{
    public const MAX_ATTEMPTS = 3;

    /** @return mixed */
    public function run(Closure $callback, string $operation): mixed
    {
        $effects = new TransactionSideEffects();

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $result = DB::transaction(fn (): mixed => $callback($effects));
                $effects->committed();

                return $result;
            } catch (Throwable $exception) {
                if (! $this->isRetryable($exception) || $attempt === self::MAX_ATTEMPTS) {
                    $effects->rolledBack();

                    if ($this->isRetryable($exception)) {
                        Log::error('Database transaction retries exhausted.', [
                            'operation' => $operation,
                            'attempts' => self::MAX_ATTEMPTS,
                            'sql_state' => $this->sqlState($exception),
                        ]);
                    }

                    throw $exception;
                }

                Log::warning('Retrying a transient database transaction error.', [
                    'operation' => $operation,
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'sql_state' => $this->sqlState($exception),
                ]);

                usleep(50_000 * $attempt);
            }
        }

        throw new RuntimeException('Transaction retry loop ended unexpectedly.');
    }

    private function isRetryable(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $code = (string) $current->getCode();
            $sqlState = isset($current->errorInfo[0]) ? (string) $current->errorInfo[0] : null;
            $driverCode = isset($current->errorInfo[1]) ? (string) $current->errorInfo[1] : null;

            if ($code === '40001' || $sqlState === '40001' || $driverCode === '1213') {
                return true;
            }
        }

        return false;
    }

    private function sqlState(Throwable $exception): string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (isset($current->errorInfo[0])) {
                return (string) $current->errorInfo[0];
            }
        }

        return (string) $exception->getCode();
    }
}
