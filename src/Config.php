<?php

namespace Bereza\LaravelSync;

/**
 * Class Config
 * @package Bereza\LaravelSync
 */
class Config
{
    /**
     * @param $key
     * @param null $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        return config('sync.' . $key, $default);
    }
}
