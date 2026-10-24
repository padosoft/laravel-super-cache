<?php

namespace Padosoft\SuperCache\Test\Unit;

use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\Service\MemoryExhaustionLogger;

class MemoryExhaustionLoggerTest extends TestCase
{
    private const OOM_ERROR = [
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)',
        'file' => '/app/vendor/padosoft/laravel-super-cache/src/SuperCacheManager.php',
        'line' => 42,
    ];

    public function test_logs_the_tag_being_flushed_when_php_died_for_memory_exhaustion(): void
    {
        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
            return $message === MemoryExhaustionLogger::MESSAGE
                && $context['tag'] === 'products'
                && $context['line'] === 42
                && str_contains($context['error'], 'Allowed memory size');
        });

        MemoryExhaustionLogger::handle(self::OOM_ERROR, 'products');
    }

    public function test_does_not_log_when_memory_exhaustion_happened_outside_flush_by_tags(): void
    {
        Log::shouldReceive('error')->never();

        MemoryExhaustionLogger::handle(self::OOM_ERROR, null);
    }

    public function test_does_not_log_other_fatal_errors(): void
    {
        Log::shouldReceive('error')->never();

        MemoryExhaustionLogger::handle([
            'type' => E_ERROR,
            'message' => 'Call to undefined function foo()',
            'file' => '/app/x.php',
            'line' => 1,
        ], 'products');
    }

    public function test_does_not_log_without_error(): void
    {
        Log::shouldReceive('error')->never();

        MemoryExhaustionLogger::handle(null, 'products');
    }
}
