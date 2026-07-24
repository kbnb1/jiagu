<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 套餐模型
 * 表名: plans
 */
class Plan extends Model
{
    protected $autoWriteTimestamp = false;
    protected $updateTime = false;
    protected $createTime = false;

    protected $type = [
        'id'           => 'integer',
        'price'        => 'float',
        'daily_quota'  => 'integer',
        'max_file_size'=> 'integer',
        'status'       => 'integer',
        'sort_order'   => 'integer',
    ];

    // JSON字段
    protected $json = ['features'];
    protected $jsonAssoc = true;

    // 状态常量
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED  = 1;

    // 预设套餐ID
    public const PLAN_FREE      = 1;
    public const PLAN_BASIC     = 2;
    public const PLAN_PRO       = 3;
    public const PLAN_ENTERPRISE = 4;

    /**
     * 是否启用
     */
    public function isEnabled(): bool
    {
        return (int) $this->getData('status') === self::STATUS_ENABLED;
    }

    /**
     * 是否无限配额
     */
    public function isUnlimited(): bool
    {
        return (int) $this->getData('daily_quota') === 0;
    }

    /**
     * 获取默认特性
     */
    public static function defaultFeatures(int $level = 1): array
    {
        return [
            'languages'        => ['php', 'java', 'javascript', 'python', 'cpp'],
            'max_obfuscate'    => $level,
            'encrypt_strings'  => $level >= 2,
            'control_flow'     => $level >= 3,
            'priority_support' => $level >= 3,
        ];
    }

    /**
     * 获取启用的套餐列表(按排序)
     */
    public static function getEnabledList(): array
    {
        return self::where('status', self::STATUS_ENABLED)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 根据ID获取套餐
     */
    public static function getById(int $id): ?array
    {
        $plan = self::find($id);
        return $plan ? $plan->toArray() : null;
    }
}
