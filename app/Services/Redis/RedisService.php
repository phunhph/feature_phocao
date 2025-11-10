<?php

namespace App\Services\Redis;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisService
{    
    /**
     * Reset cache theo key
     *
     * @param string $key
     * @return void
     */
    public function reset(string $key)
    {
        Cache::forget($key);    
        Redis::del($key . '_lock');
    }

    /**
     * Set trực tiếp dữ liệu vào cache
     *
     * @param string $key
     * @param mixed $value
     * @param int|null $ttl
     * @return void
     */
    public function set(string $key, $value, ?int $ttl = null)
    {
        Cache::put($key, $value, $ttl ?? $this->cacheTTL);
    }

    /**
     * Lấy dữ liệu trực tiếp từ cache (không dùng callback)
     */
    public function get(string $key)
    {
        return Cache::get($key);
    }

    public function lock(string $key, int $ttl = 5): bool
    {
        $result = Redis::set($key, 1, 'NX', 'EX', $ttl);

        // Nếu Predis trả Status object
        if ($result instanceof \Predis\Response\Status) {
            return $result->getPayload() === 'OK';
        }

        // Nếu PhpRedis trả true/false
        return (bool) $result;
    }

    public function unlock(string $key): void
    {
        Redis::del($key);
    }

}
