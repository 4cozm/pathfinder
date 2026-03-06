<?php

/**
 * IDE stub for Fat-Free Framework (bcosca/fatfree-core) Base class.
 * Not loaded at runtime — Composer autoloads the real class from vendor.
 * Ensure this file is preferred by your IDE (e.g. add ide-stubs to include path).
 *
 * @see https://fatfreeframework.com/base
 * @method int|void status(int $code) Set HTTP response status code.
 * @method object ccpClient() Pathfinder CCP API client.
 * @method object webSocket() WebSocket helper.
 * @method void clear(string $key) Clear hive key.
 * @method void reroute(string|array $url, bool $permanent = false)
 * @method string alias(string $name, array $params = [])
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

    /**
     * Redirect to another URL/route.
     * @param string|array $url Route name, URL string, or [alias, params]
     * @param bool $permanent Send 301 (true) or 303 (false)
     * @return void
     */
    public function reroute($url, $permanent = false): void
    {
    }

    /**
     * Build URL from named route and token params.
     * @param string $name Route name (e.g. 'admin')
     * @param array $params Token params (e.g. ['*' => '/settings'])
     * @return string
     */
    public function alias(string $name, array $params = []): string
    {
        return '';
    }
}
