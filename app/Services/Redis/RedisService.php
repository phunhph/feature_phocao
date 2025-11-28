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

    /**
     * Xóa cache theo pattern (dùng cho pagination)
     * Production-safe: Dùng SCAN thay vì KEYS để tránh block Redis
     * @param string $pattern
     */
    public function delPattern(string $pattern)
    {
        $cursor = '0';
        $matchPattern = '*' . $pattern . '*';
        
        do {
            // SCAN không block Redis, xử lý từng batch nhỏ
            $result = Redis::scan($cursor, ['match' => $matchPattern, 'count' => 100]);
            
            // Nếu tìm thấy keys, xóa ngay
            if ($result !== false && is_array($result) && count($result) > 1) {
                $cursor = $result[0]; // Cursor cho lần scan tiếp theo
                $keys = $result[1];   // Keys tìm được
                
                if (!empty($keys)) {
                    Redis::del($keys);
                }
            } else {
                // Fallback về KEYS nếu SCAN không hoạt động (môi trường dev)
                $keys = Redis::keys($matchPattern);
                if (!empty($keys)) {
                    Redis::del($keys);
                }
                break;
            }
        } while ($cursor !== '0');
    }

}
