<?php

/**
 * IDE stub for Fat-Free Framework (bcosca/fatfree-core) Base class.
 * Not loaded at runtime — Composer autoloads the real class from vendor.
 *
 * @see https://fatfreeframework.com/base
 * @method int|void status(int $code) Set HTTP response status code.
 * @method object ccpClient() Pathfinder CCP API client.
 * @method object webSocket() WebSocket helper.
 * @method void clear(string $key) Clear hive key.
 */
class Base
{
    public static function instance(): self
    {
        return new self();
    }

    public function get($key)
    {
        return null;
    }

    public function set($key, $value = null)
    {
        return null;
    }

    public function exists($key, &$var = null): bool
    {
        return false;
    }

    public function error(int $code, string $text = ''): void
    {
    }

    /**
     * Set HTTP response status code.
     * @param int $code
     * @return int|void
     */
    public function status(int $code)
    {
        return $code;
    }
}
