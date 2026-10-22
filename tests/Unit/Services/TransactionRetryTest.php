<?php

namespace Tests\Unit\Services;

use App\Services\TransactionRetry;
use RuntimeException;
use Tests\TestCase;

class TransactionRetryTest extends TestCase
{
    public function test_mysql_deadlock_is_retried_as_a_complete_operation(): void
    {
        $attempts = 0;

        $result = app(TransactionRetry::class)->run(function () use (&$attempts): string {
            $attempts++;

            if ($attempts === 1) {
                $exception = new class('deadlock') extends RuntimeException {
                    public array $errorInfo = ['40001', 1213, 'deadlock'];
                };

                throw $exception;
            }

            return 'committed';
        }, 'test.deadlock');

        $this->assertSame('committed', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_non_transient_errors_are_not_retried(): void
    {
        $attempts = 0;

        $this->expectException(RuntimeException::class);

        try {
            app(TransactionRetry::class)->run(function () use (&$attempts): never {
                $attempts++;
                throw new RuntimeException('validation failure');
            }, 'test.non_transient');
        } finally {
            $this->assertSame(1, $attempts);
        }
    }
}
