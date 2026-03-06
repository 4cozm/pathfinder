<?php

/**
 * IDE stub for Fat-Free Framework (bcosca/fatfree-core) Cache class.
 * Not loaded at runtime — Composer autoloads the real class from vendor.
 *
 * @see https://fatfreeframework.com/cache
 */
class Cache
{
    /**
     * Return singleton instance.
     * @return self
     */
    public static function instance(): self
    {
        return new self();
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get($key)
    {
        return null;
    }

    /**
     * @param string $key
     * @param mixed $val
     * @param int $ttl
     * @return mixed
     */
    public function set($key, $val, $ttl = 0)
    {
        return null;
    }
}
