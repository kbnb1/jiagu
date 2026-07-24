<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 加固任务模型
 * 表名: tasks
 */
class Task extends Model
{
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $type = [
        'id'         => 'integer',
        'user_id'    => 'integer',
        'progress'   => 'integer',
        'file_size'  => 'integer',
        'duration'   => 'integer',
    ];

    // JSON字段
    protected $json = ['options'];
    protected $jsonAssoc = true;

    // 状态常量
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';

    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // 关联账户
    public function account()
    {
        return $this->belongsTo(UserAccount::class, 'user_id', 'user_id');
    }

    /**
     * 生成任务编号
     */
    public static function generateTaskNo(): string
    {
        return 'TASK' . date('YmdHis') . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 标记为处理中
     */
    public function markProcessing(): void
    {
        $this->save([
            'status'   => self::STATUS_PROCESSING,
            'progress' => 0,
        ]);
    }

    /**
     * 更新进度
     */
    public function updateProgress(int $progress): void
    {
        $this->save(['progress' => max(0, min(100, $progress))]);
    }

    /**
     * 标记为完成
     */
    public function markCompleted(string $resultFile, int $duration): void
    {
        $this->save([
            'status'       => self::STATUS_COMPLETED,
            'progress'     => 100,
            'result_file'  => $resultFile,
            'duration'     => $duration,
            'completed_at' => date('Y-m-d H:i:s'),
            'error_msg'    => null,
        ]);
    }

    /**
     * 标记为失败
     */
    public function markFailed(string $errorMsg): void
    {
        $this->save([
            'status'       => self::STATUS_FAILED,
            'completed_at' => date('Y-m-d H:i:s'),
            'error_msg'    => $errorMsg,
        ]);
    }
}
