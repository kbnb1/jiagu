<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\traits\ApiResponse;
use app\common\model\Plan;
use app\common\model\Order;
use app\common\model\UserAccount;
use think\facade\Db;
use think\facade\Config;

/**
 * 套餐计划控制器
 */
class PlanController extends BaseController
{
    use ApiResponse;

    /**
     * 套餐列表
     * GET /api/plan/list
     */
    public function list()
    {
        $list = Plan::getEnabledList();
        return $this->success([
            'list'  => $list,
            'total' => count($list),
        ]);
    }

    /**
     * 套餐详情
     * GET /api/plan/:id
     */
    public function detail($id)
    {
        $plan = Plan::find((int) $id);
        if (!$plan) {
            return $this->fail('套餐不存在', 3001, null, 404);
        }
        if (!$plan->isEnabled()) {
            return $this->fail('套餐已下架', 3002);
        }
        return $this->success($plan->toArray());
    }

    /**
     * 当前用户套餐
     * GET /api/plan/current
     */
    public function current()
    {
        $uid = $this->userId();
        if ($uid <= 0) {
            return $this->fail('请先登录', 3003, null, 401);
        }
        $account = UserAccount::where('user_id', $uid)->find();
        if (!$account) {
            return $this->fail('账户信息不存在', 3004, null, 404);
        }
        $account->resetDailyQuotaIfNeeded();
        $plan = $account->getPlan;
        $data = [
            'plan'      => $plan ? $plan->toArray() : null,
            'account'   => [
                'daily_quota'    => (int) $account->daily_quota,
                'used_today'     => (int) $account->used_today,
                'remaining'      => (int) $account->daily_quota === 0
                    ? -1
                    : max(0, (int) $account->daily_quota - (int) $account->used_today),
                'total_tasks'    => (int) $account->total_tasks,
                'plan_expire_at' => $account->plan_expire_at,
                'is_expired'     => $account->isPlanExpired(),
            ],
        ];
        return $this->success($data);
    }

    /**
     * 用户订单列表
     * GET /api/plan/orders
     */
    public function orders()
    {
        $uid = $this->userId();
        if ($uid <= 0) {
            return $this->fail('请先登录', 3003, null, 401);
        }
        [$page, $size] = $this->pageParams();
        $query = Order::where('user_id', $uid);
        $total = $query->count();
        $list = $query->with(['plan'])
            ->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();
        return $this->success([
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $size,
        ]);
    }

    /**
     * 创建订单
     * POST /api/plan/order
     */
    public function createOrder()
    {
        $uid = $this->userId();
        if ($uid <= 0) {
            return $this->fail('请先登录', 3003, null, 401);
        }
        $planId = (int) $this->request->post('plan_id', 0);
        if ($planId <= 0) {
            return $this->fail('请选择套餐', 3011);
        }
        $plan = Plan::find($planId);
        if (!$plan || !$plan->isEnabled()) {
            return $this->fail('套餐不存在或已下架', 3012);
        }
        // 免费套餐不能下单
        if ((float) $plan->price <= 0) {
            return $this->fail('免费套餐无需购买', 3013);
        }

        // 过期时间: 套餐周期默认30天
        $period = 30;
        $expireAt = date('Y-m-d 23:59:59', strtotime('+' . $period . ' days'));

        Db::startTrans();
        try {
            $order = Order::create([
                'order_no'  => Order::generateOrderNo(),
                'user_id'   => $uid,
                'plan_id'   => $planId,
                'amount'    => (float) $plan->price,
                'status'    => Order::STATUS_PENDING,
                'expire_at' => $expireAt,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('创建订单失败: ' . $e->getMessage(), 3014);
        }

        return $this->success([
            'order_id'    => (int) $order->id,
            'order_no'    => $order->order_no,
            'amount'      => (float) $order->amount,
            'expire_at'   => $order->expire_at,
            'pay_methods' => ['alipay', 'wechat', 'balance'],
        ], '订单已创建,请尽快支付');
    }
}
