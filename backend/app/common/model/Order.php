<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 订单模型
 * 表名: orders
 */
class Order extends Model
{
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
    protected $dateFormat = 'Y-m-d H:i:s';

    protected $type = [
        'id'     => 'integer',
        'user_id'=> 'integer',
        'plan_id'=> 'integer',
        'amount' => 'float',
    ];

    // 状态常量
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED  = 'refunded';

    // 关联用户
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    // 关联套餐
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    /**
     * 生成订单号
     */
    public static function generateOrderNo(): string
    {
        return 'ORD' . date('YmdHis') . str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
    }

    /**
     * 标记为已支付
     */
    public function markPaid(string $paymentMethod): void
    {
        $this->save([
            'status'         => self::STATUS_PAID,
            'payment_method' => $paymentMethod,
            'paid_at'        => date('Y-m-d H:i:s'),
        ]);
    }
}
