<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Peak memory growth caused by $work, in bytes, measured from the usage just before it runs.
     */
    protected static function peakGrowth(callable $work): int
    {
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $work();

        return memory_get_peak_usage() - $before;
    }

    /**
     * Runs $work and fails when it raises any PHP error, warning or notice, including ones
     * silenced with the @ operator.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    protected static function withoutPhpErrors(callable $work): mixed
    {
        $errors = [];
        set_error_handler(static function (int $level, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });
        error_clear_last();

        try {
            return $work();
        } finally {
            restore_error_handler();
            $last = error_get_last();

            if ($last !== null) {
                $errors[] = $last['message'];
            }

            self::assertSame([], $errors, 'PHP raised errors: '.implode('; ', $errors));
        }
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();

        parent::tearDown();
    }
}
