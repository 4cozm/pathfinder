<?php

/**
 * IDE stub for DB\Cortex (f3-cortex ORM).
 * Not loaded at runtime — Composer autoloads the real class from vendor.
 */
namespace DB;

class Cortex
{
    /**
     * @param string $key
     * @param bool $raw
     * @return mixed
     */
    public function get($key, $raw = false)
    {
        return null;
    }

    /** @return bool */
    public function dry()
    {
        return true;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function rel($key)
    {
        return null;
    }

    /** @return bool */
    public function valid()
    {
        return false;
    }

    /**
     * @param array $var
     * @param array|null $keys
     * @return void
     */
    public function copyfrom($var, $keys = null)
    {
    }

    /** @return void */
    public function erase()
    {
    }

    /**
     * @param string $key
     * @return void
     */
    public function clear($key)
    {
    }

    /**
     * @param string $key
     * @param mixed $cond
     * @param array|null $options
     * @return static
     */
    public function filter($key, $cond = null, $options = null)
    {
        return $this;
    }

    /**
     * @param array|null $filter
     * @param array|null $options
     * @return CortexCollection
     */
    public function find($filter = null, $options = null)
    {
        return new CortexCollection();
    }
}
