<?php

namespace Padosoft\SuperCache\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void  put(string $key, mixed $value, ?int $ttl = null)
 * @method static void  putWithTags(string $key, mixed $value, array $tags, ?int $ttl = null)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool  has(string $key)
 * @method static int   increment(string $key, int $increment = 1)
 * @method static int   decrement(string $key, int $decrement = 1)
 * @method static void  flush()
 * @method static array getKeys(array $patterns)
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
