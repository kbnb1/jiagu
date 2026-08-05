<?php
declare(strict_types=1);

namespace app\common\service;

use Redis;
use RuntimeException;

/**
 * 任务队列服务
 *
 * 基于 Redis 实现的任务队列，支持推送、弹出、状态查询与更新。
 * 当 Redis 不可用时自动回退到基于文件的队列实现。
 *
 * 任务数据结构：
 *   - job_id:   唯一任务 ID
 *   - queue:    队列名
 *   - data:     任务负载数组
 *   - status:   pending | processing | done | failed
 *   - result:   任务结果
 *   - created_at / updated_at
 *   - attempts: 重试次数
 */
class QueueService
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    /** @var Redis|null Redis 客户端 */
    private $redis = null;

    /** @var bool 是否使用 Redis */
    private bool $useRedis = false;

    /** @var string 文件队列根目录 */
    private string $fileRoot;

    /** @var string 状态存储目录 */
    private string $statusDir;

    /**
     * @param array $config 配置：
     *   - redis:        Redis 实例（提供则优先使用）
     *   - redis_host:   Redis 主机
     *   - redis_port:   Redis 端口
     *   - file_root:    文件队列根目录
     */
    public function __construct(array $config = [])
    {
        $this->fileRoot  = $config['file_root'] ?? (dirname(__DIR__, 3) . '/runtime/queue');
        $this->statusDir = $this->fileRoot . '/status';

        if (isset($config['redis']) && $config['redis'] instanceof Redis) {
            $this->redis = $config['redis'];
            $this->useRedis = true;
        } elseif (class_exists(Redis::class) && !empty($config['redis_host'])) {
            try {
                $this->redis = new Redis();
                $connected = $this->redis->connect(
                    $config['redis_host'],
                    (int)($config['redis_port'] ?? 6379),
                    (float)($config['redis_timeout'] ?? 2.0)
                );
                $this->useRedis = $connected;
            } catch (\Throwable $e) {
                $this->useRedis = false;
            }
        }

        if (!$this->useRedis) {
            foreach ([$this->fileRoot, $this->statusDir] as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
            }
        }
    }

    /**
     * 推送任务到队列，返回 job_id。
     *
     * @param string $queue 队列名
     * @param array  $data  任务负载
     * @return string job_id
     */
    public function push(string $queue, array $data): string
    {
        $jobId = $this->generateJobId();
        $job = [
            'job_id'     => $jobId,
            'queue'      => $queue,
            'data'       => $data,
            'status'     => self::STATUS_PENDING,
            'result'     => [],
            'attempts'   => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $payload = json_encode($job, JSON_UNESCAPED_UNICODE);
        $queueKey = 'queue:' . $queue;
        $statusKey = 'job:' . $jobId;

        if ($this->useRedis) {
            // 使用 Redis 事务保证原子性
            $this->redis->multi();
            $this->redis->rPush($queueKey, $payload);
            $this->redis->set($statusKey, $payload);
            $result = $this->redis->exec();

            // 验证事务执行结果，防止状态不一致
            if (!is_array($result) || count($result) !== 2) {
                throw new RuntimeException('Queue push failed: Redis transaction incomplete');
            }
            if ($result[0] === false || $result[1] === false) {
                throw new RuntimeException('Queue push failed: Redis operation failed');
            }
        } else {
            // 文件模式：状态文件 + 队列文件追加
            file_put_contents($this->statusDir . '/' . $jobId . '.json', $payload, LOCK_EX);
            file_put_contents($this->queueFilePath($queue), $payload . "\n", FILE_APPEND | LOCK_EX);
        }

        return $jobId;
    }

    /**
     * 从队列弹出一个待处理任务。
     *
     * @param string $queue 队列名
     * @return array|null 任务数据（含 job_id），无任务时返回 null
     */
    public function pop(string $queue): ?array
    {
        $queueKey = 'queue:' . $queue;

        if ($this->useRedis) {
            $payload = $this->redis->lPop($queueKey);
            if ($payload === false || $payload === null) {
                return null;
            }
        } else {
            $file = $this->queueFilePath($queue);
            if (!file_exists($file) || filesize($file) === 0) {
                return null;
            }

            // 使用文件锁防止并发竞态条件
            $handle = fopen($file, 'c+');
            if ($handle === false) {
                return null;
            }

            if (!flock($handle, LOCK_EX)) {
                fclose($handle);
                return null;
            }

            try {
                // 清空文件句柄的读取缓冲区
                $content = stream_get_contents($handle);
                $lines = explode("\n", trim($content));
                if (empty($lines[0])) {
                    return null;
                }

                $payload = $lines[0];
                // 重写队列文件，移除已弹出任务
                array_shift($lines);
                $remaining = implode("\n", $lines);

                // 原子操作：清空文件并写入新内容
                ftruncate($handle, 0);
                rewind($handle);
                if ($remaining !== '') {
                    fwrite($handle, $remaining . "\n");
                }
                fflush($handle);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        $job = json_decode($payload, true);
        if (!is_array($job)) {
            return null;
        }

        // 标记为处理中
        $job['status'] = self::STATUS_PROCESSING;
        $job['updated_at'] = date('Y-m-d H:i:s');
        $this->updateJobStatus($job['job_id'], self::STATUS_PROCESSING);

        return $job;
    }

    /**
     * 获取任务状态。
     *
     * @param string $jobId
     * @return array|null
     */
    public function getJobStatus(string $jobId): ?array
    {
        if ($this->useRedis) {
            $payload = $this->redis->get('job:' . $jobId);
            if ($payload === false || $payload === null) {
                return null;
            }
            $job = json_decode($payload, true);
            return is_array($job) ? $job : null;
        }

        $file = $this->statusDir . '/' . $jobId . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $job = json_decode(file_get_contents($file), true);
        return is_array($job) ? $job : null;
    }

    /**
     * 更新任务状态与结果。
     *
     * @param string $jobId
     * @param string $status
     * @param array  $result
     */
    public function updateJobStatus(string $jobId, string $status, array $result = []): void
    {
        $job = $this->getJobStatus($jobId);
        if ($job === null) {
            return;
        }
        $job['status'] = $status;
        $job['updated_at'] = date('Y-m-d H:i:s');
        if ($status === self::STATUS_PROCESSING) {
            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
        }
        if (!empty($result)) {
            $job['result'] = $result;
        }
        $payload = json_encode($job, JSON_UNESCAPED_UNICODE);

        if ($this->useRedis) {
            $this->redis->set('job:' . $jobId, $payload);
        } else {
            file_put_contents($this->statusDir . '/' . $jobId . '.json', $payload, LOCK_EX);
        }
    }

    /**
     * 获取队列长度。
     */
    public function queueSize(string $queue): int
    {
        if ($this->useRedis) {
            return (int)$this->redis->lLen('queue:' . $queue);
        }
        $file = $this->queueFilePath($queue);
        if (!file_exists($file)) {
            return 0;
        }
        $content = file_get_contents($file);
        return $content === '' ? 0 : count(array_filter(explode("\n", trim($content))));
    }

    /**
     * 是否使用 Redis 后端。
     */
    public function isUsingRedis(): bool
    {
        return $this->useRedis;
    }

    /* ---------------------------------------------------------------------
     * 内部实现
     * ------------------------------------------------------------------- */

    private function generateJobId(): string
    {
        return 'job_' . bin2hex(random_bytes(8)) . dechex(time());
    }

    private function queueFilePath(string $queue): string
    {
        return $this->fileRoot . '/q_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $queue) . '.log';
    }
}
