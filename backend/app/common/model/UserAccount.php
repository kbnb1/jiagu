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
     * 增加今日使用量（非原子，已废弃，请使用 tryIncrementUsage）
     * @deprecated 存在竞态条件，请使用 tryIncrementUsage
     */
    public function incrementUsage(): void
    {
        $this->inc('used_today')->update();
        $this->inc('total_tasks')->update();
    }

    /**
     * 原子增加使用量：在检查配额的同时递增，防止竞态条件。
     *
     * @return bool true 表示成功，false 表示配额已满
     */
    public function tryIncrementUsage(): bool
    {
        $today = date('Y-m-d');
        $lastReset = (string) $this->getData('last_reset_date');

        // 跨天重置逻辑需要在事务外先处理
        if ($lastReset !== $today) {
            $this->save([
                'used_today'      => 0,
                'last_reset_date' => $today,
            ]);
            $this->refresh();
        }

        // daily_quota = 0 表示无限配额
        $dailyQuota = (int) $this->getData('daily_quota');
        if ($dailyQuota === 0) {
            // 无限配额，直接增加
            Db::execute(
                'UPDATE user_accounts SET used_today = used_today + 1, total_tasks = total_tasks + 1 WHERE id = ?',
                [$this->id]
            );
            return true;
        }

        // 使用原子UPDATE，WHERE条件中包含配额检查
        $affected = Db::execute(
            'UPDATE user_accounts SET used_today = used_today + 1, total_tasks = total_tasks + 1 WHERE id = ? AND used_today < ?',
            [$this->id, $dailyQuota]
        );

        return $affected > 0;
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
