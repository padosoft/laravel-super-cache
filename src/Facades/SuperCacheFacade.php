<?php

namespace Padosoft\SuperCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void  put(string $key, mixed $value, ?int $ttl = null, ?string $connection_name = null)
 * @method static void  putWithTags(string $key, mixed $value, array $tags, ?int $ttl = null, ?string $connection_name = null)
 * @method static mixed get(string $key, mixed $default = null, ?string $connection_name = null)
 * @method static mixed forget(string $key, ?string $connection_name = null)
 * @method static bool  has(string $key, ?string $connection_name = null)
 * @method static int   increment(string $key, int $increment = 1, ?string $connection_name = null)
 * @method static int   decrement(string $key, int $decrement = 1, ?string $connection_name = null)
 * @method static void  flush(?string $connection_name = null)
 * @method static array getKeys(array $patterns, ?string $connection_name = null)
 */
class SuperCacheFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'supercache';
    }
}
