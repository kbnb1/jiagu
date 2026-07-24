<?php
declare(strict_types=1);

namespace app\command;

use app\common\hardener\HardenerFactory;
use app\common\service\QueueService;
use RuntimeException;
use Throwable;

/**
 * 加固任务队列 Worker 命令
 *
 * 从队列消费加固任务，调用 HardenerFactory 执行加固，
 * 更新任务状态与结果，支持最多 3 次重试与错误日志记录。
 *
 * 用法：
 *   $worker = new HardeningWorker($queueService);
 *   $worker->work('hardening', ['max_jobs' => 0, 'interval' => 1]);
 */
class HardeningWorker
{
    /** 最大重试次数 */
    public const MAX_RETRIES = 3;

    /** 默认轮询间隔（微秒） */
    public const DEFAULT_INTERVAL_US = 1000000;

    /** @var QueueService 队列服务 */
    private QueueService $queue;

    /** @var string 日志目录 */
    private string $logDir;

    /** @var resource|null 日志文件句柄 */
    private $logHandle = null;

    /** @var int 已处理任务计数 */
    private int $processed = 0;

    /** @var int 失败任务计数 */
    private int $failed = 0;

    /** @var bool 是否继续运行 */
    private bool $running = true;

    public function __construct(QueueService $queue, string $logDir = '')
    {
        $this->queue = $queue;
        $this->logDir = $logDir ?: (dirname(__DIR__, 2) . '/runtime/logs');
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * 命令入口：启动 worker 循环消费队列。
     *
     * @param string $queue  队列名称
     * @param array  $options 选项：
     *   - max_jobs:  最多处理任务数（0 = 无限）
     *   - interval:  空闲轮询间隔（秒）
     *   - daemon:    是否常驻运行
     */
    public function work(string $queue = 'hardening', array $options = []): void
    {
        $maxJobs  = (int)($options['max_jobs'] ?? 0);
        $interval = (int)($options['interval'] ?? 1);
        $daemon   = (bool)($options['daemon'] ?? false);

        $this->log('HardeningWorker started, queue=' . $queue . ' daemon=' . ($daemon ? '1' : '0'));

        // 注册信号处理（优雅退出）
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->stop());
            pcntl_signal(SIGINT,  fn() => $this->stop());
        }

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $job = $this->queue->pop($queue);
            if ($job === null) {
                if (!$daemon) {
                    // 非常驻模式：队列为空则退出
                    $this->log('Queue empty, exiting (non-daemon mode)');
                    break;
                }
                usleep($interval * self::DEFAULT_INTERVAL_US);
                continue;
            }

            try {
                $this->process($job);
                $this->processed++;
            } catch (Throwable $e) {
                $this->failed++;
                $this->log('Job ' . ($job['job_id'] ?? '?') . ' failed: ' . $e->getMessage());
            }

            if ($maxJobs > 0 && $this->processed >= $maxJobs) {
                $this->log('Reached max_jobs=' . $maxJobs . ', stopping');
                break;
            }
        }

        $this->log('HardeningWorker stopped, processed=' . $this->processed . ' failed=' . $this->failed);
    }

    /**
     * 处理单个加固任务。
     *
     * @param array $job 任务结构：['job_id', 'data' => ['language','code','options','retry_count']]
     */
    public function process(array $job): void
    {
        $jobId  = $job['job_id'] ?? '';
        $data   = $job['data'] ?? [];
        $lang   = $data['language'] ?? '';
        $code   = $data['code'] ?? '';
        $opts   = $data['options'] ?? [];
        $userId = $data['user_id'] ?? 0;
        // 重试计数随 job.data 传递，跨重试累积（不会因新 job_id 重置）
        $retryCount = (int)($data['retry_count'] ?? 0);

        if ($lang === '' || $code === '') {
            $this->queue->updateJobStatus($jobId, QueueService::STATUS_FAILED, [
                'error' => 'missing language or code',
            ]);
            $this->log("Job {$jobId} rejected: missing language or code");
            return;
        }

        $this->log("Job {$jobId} processing: lang={$lang} user={$userId} retry={$retryCount} size=" . strlen($code));

        try {
            $hardener = HardenerFactory::create($lang);
            $result   = $hardener->harden($code, $opts);

            $this->queue->updateJobStatus($jobId, QueueService::STATUS_DONE, [
                'language'     => $lang,
                'output'       => $result,
                'output_size'  => strlen($result),
                'identifier_map' => method_exists($hardener, 'getIdentifierMap')
                    ? $hardener->getIdentifierMap() : [],
            ]);
            $this->log("Job {$jobId} done: output_size=" . strlen($result));
        } catch (Throwable $e) {
            $this->handleFailure($job, $e, $retryCount);
        }
    }

    /**
     * 处理任务失败：判定是否可重试。
     *
     * @param array     $job        原始任务
     * @param Throwable $e          异常
     * @param int       $retryCount 当前已重试次数（来自 job.data.retry_count）
     */
    protected function handleFailure(array $job, Throwable $e, int $retryCount): void
    {
        $jobId = $job['job_id'] ?? '';
        $origData = $job['data'] ?? [];

        // 当前是第 retryCount 次重试，本次失败后若仍小于上限则再重试一次
        if ($retryCount < self::MAX_RETRIES) {
            $nextRetry = $retryCount + 1;
            $this->log("Job {$jobId} retry={$retryCount} failed, requeuing as retry={$nextRetry}: " . $e->getMessage());
            // 将原任务标记为已重试（保留错误信息便于追踪）
            $this->queue->updateJobStatus($jobId, QueueService::STATUS_PENDING, [
                'last_error'  => $e->getMessage(),
                'retry_count' => $retryCount,
                'retried'     => true,
            ]);
            // 重新推入队列，retry_count 递增确保最终停止
            $this->queue->push($job['queue'] ?? 'hardening', [
                'language'    => $origData['language'] ?? '',
                'code'        => $origData['code'] ?? '',
                'options'     => $origData['options'] ?? [],
                'user_id'     => $origData['user_id'] ?? 0,
                'retry_count' => $nextRetry,
                'retry_of'    => $jobId,
            ]);
        } else {
            // 达到最大重试次数，标记失败
            $this->log("Job {$jobId} exhausted retries ({$retryCount}), marking failed: " . $e->getMessage());
            $this->queue->updateJobStatus($jobId, QueueService::STATUS_FAILED, [
                'error'       => $e->getMessage(),
                'retry_count' => $retryCount,
            ]);
            $this->writeErrorLog($job, $e);
        }
    }

    /**
     * 停止 worker。
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * 获取统计信息。
     */
    public function getStats(): array
    {
        return [
            'processed' => $this->processed,
            'failed'    => $this->failed,
            'running'   => $this->running,
        ];
    }

    /* ---------------------------------------------------------------------
     * 日志
     * ------------------------------------------------------------------- */

    protected function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        $file = $this->logDir . '/worker_' . date('Ymd') . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    protected function writeErrorLog(array $job, Throwable $e): void
    {
        $record = [
            'time'       => date('Y-m-d H:i:s'),
            'job_id'     => $job['job_id'] ?? '',
            'queue'      => $job['queue'] ?? '',
            'language'   => $job['data']['language'] ?? '',
            'code_size'  => strlen($job['data']['code'] ?? ''),
            'error'      => $e->getMessage(),
            'trace'      => $e->getTraceAsString(),
        ];
        $file = $this->logDir . '/worker_errors_' . date('Ymd') . '.log';
        @file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * 命令行入口（兼容 ThinkPHP Command 调用）。
     *
     * @param array $args CLI 参数
     */
    public function execute(array $args = []): int
    {
        $queue   = $args[0] ?? 'hardening';
        $maxJobs = (int)($args[1] ?? 0);
        $daemon  = ($args[2] ?? '0') === '1';

        $this->work($queue, [
            'max_jobs' => $maxJobs,
            'daemon'   => $daemon,
            'interval' => 1,
        ]);
        return 0;
    }
}
