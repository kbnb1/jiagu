<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\traits\ApiResponse;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Config;

/**
 * 健康检查控制器
 */
class HealthController extends BaseController
{
    use ApiResponse;

    /**
     * ping 探活
     * GET /api/health/ping
     */
    public function ping()
    {
        return $this->success([
            'pong' => true,
            'time' => date('Y-m-d H:i:s'),
        ], 'pong');
    }

    /**
     * 系统状态
     * GET /api/health/status
     */
    public function status()
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'queue'    => $this->checkQueue(),
        ];
        $allOk = true;
        foreach ($checks as $v) {
            if ($v !== 'ok') {
                $allOk = false;
                break;
            }
        }
        return $this->json(
            $allOk ? 0 : 1,
            $allOk ? '系统正常' : '存在异常',
            [
                'status'     => $allOk ? 'healthy' : 'degraded',
                'app'        => Config::get('app.name', 'CodeHardening'),
                'env'        => Config::get('app.env', 'production'),
                'time'       => date('Y-m-d H:i:s'),
                'components' => $checks,
            ]
        );
    }

    /**
     * 检查数据库
     */
    private function checkDatabase(): string
    {
        try {
            Db::query('SELECT 1');
            return 'ok';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * 检查Redis
     */
    private function checkRedis(): string
    {
        try {
            $cache = Cache::store('redis');
            $cache->set('health:check', '1', 5);
            $val = $cache->get('health:check');
            return $val === '1' ? 'ok' : 'error: read mismatch';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }

    /**
     * 检查队列(检查待处理任务数)
     */
    private function checkQueue(): string
    {
        try {
            $size = Cache::store('redis')->lLen('queue:hardening');
            return 'ok(' . (int) $size . ')';
        } catch (\Throwable $e) {
            return 'error: ' . $e->getMessage();
        }
    }
}
