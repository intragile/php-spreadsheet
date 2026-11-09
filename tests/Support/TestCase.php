<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Peak memory growth caused by $work, in bytes, measured from the usage just before it runs.
     *
     * Before PHP 8.2 the peak cannot be reset, so the growth is measured against the previous
     * peak and may be underestimated.
     */
    protected static function peakGrowth(callable $work): int
    {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
            $before = memory_get_usage();
        } else {
            $before = memory_get_peak_usage();
        }

        $work();

        return memory_get_peak_usage() - $before;
    }

    protected function tearDown(): void
    {
        Fixtures::cleanup();

        parent::tearDown();
    }
}
