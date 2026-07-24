<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;
use think\facade\Hash;

/**
 * 用户模型
 * 表名: users
 */
class User extends Model
{
    // 自动写入时间戳
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 日期字段
    protected $dateFormat = 'Y-m-d H:i:s';

    // 隐藏敏感字段
    protected $hidden = ['password_hash'];

    // 类型转换
    protected $type = [
        'id'       => 'integer',
        'is_admin' => 'integer',
        'status'   => 'integer',
    ];

    // 字段设置器: 密码自动hash
    public function setPasswordHashAttr($value): string
    {
        if (empty($value)) {
            return '';
        }
        // 已经是hash过的不再重复处理
        if (strpos($value, '$2y$') === 0 && strlen($value) === 60) {
            return $value;
        }
        return password_hash($value, PASSWORD_DEFAULT);
    }

    // 校验明文密码
    public function verifyPassword(string $plain): bool
    {
        return password_verify($plain, (string) $this->getData('password_hash'));
    }

    // 状态判断
    public function isActive(): bool
    {
        return (int) $this->getData('status') === 1;
    }

    public function isAdmin(): bool
    {
        return (int) $this->getData('is_admin') === 1;
    }

    // 关联账户
    public function account()
    {
        return $this->hasOne(UserAccount::class, 'user_id', 'id');
    }
}
