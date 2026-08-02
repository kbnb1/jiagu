<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\facade\Db;

/**
 * 用户账户模型
 * 表名: user_accounts
 */
class UserAccount extends Model
{
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $type = [
        'id'             => 'integer',
        'user_id'        => 'integer',
        'plan_id'        => 'integer',
        'daily_quota'    => 'integer',
        'used_today'     => 'integer',
        'total_tasks'    => 'integer',
    ];

    // 关联套餐
    public function getPlan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 跨天时重置每日配额
     */
    public function resetDailyQuotaIfNeeded(): void
    {
        $today = date('Y-m-d');
        $lastReset = (string) $this->getData('last_reset_date');
        if ($lastReset !== $today) {
            $this->save([
                'used_today'      => 0,
                'last_reset_date' => $today,
            ]);
        }
    }

    /**
     * 是否还能创建任务
     */
    public function canCreateTask(): bool
    {
        $this->resetDailyQuotaIfNeeded();
        // daily_quota = 0 表示无限
        if ((int) $this->getData('daily_quota') === 0) {
            return true;
        }
        return $this->getData('used_today') < $this->getData('daily_quota');
    }

    /**
     * 原子性尝试扣减配额（防竞态条件）。
     *
     * @return bool true 表示扣减成功，false 表示配额不足
     */
    public function tryIncrementUsage(): bool
    {
        // 先重置配额（如果跨天）
        $this->resetDailyQuotaIfNeeded();

        // daily_quota = 0 表示无限
        $dailyQuota = (int) $this->getData('daily_quota');
        if ($dailyQuota === 0) {
            // 无限配额，直接更新计数（不检查上限）
            $this->inc('used_today')->inc('total_tasks')->update();
            return true;
        }

        // 使用数据库行级锁 + 条件更新（原子操作）
        $affected = Db::table('user_accounts')
            ->where('id', $this->id)
            ->where('used_today', '<', $dailyQuota)
            ->inc('used_today')
            ->inc('total_tasks')
            ->update();

        return $affected > 0;
    }

    /**
     * 增加今日使用量
     * @deprecated 请使用 tryIncrementUsage() 替代，避免竞态条件
     */
    public function incrementUsage(): void
    {
        $this->inc('used_today')->update();
        $this->inc('total_tasks')->update();
    }

    /**
     * 套餐是否过期
     */
    public function isPlanExpired(): bool
    {
        $expireAt = $this->getData('plan_expire_at');
        if (empty($expireAt)) {
            return false;
        }
        return strtotime((string) $expireAt) < time();
    }
}
