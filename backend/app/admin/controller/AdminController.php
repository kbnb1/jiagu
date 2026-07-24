<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseController;
use app\common\traits\ApiResponse;
use app\common\model\User;
use app\common\model\UserAccount;
use app\common\model\Task;
use app\common\model\Plan;
use app\common\model\Order;
use think\facade\Db;
use think\facade\Config;
use think\facade\Cache;

/**
 * 管理后台控制器(需管理员权限)
 */
class AdminController extends BaseController
{
    use ApiResponse;

    /**
     * 仪表盘统计
     * GET /admin/dashboard
     */
    public function dashboard()
    {
        $this->checkAdmin();

        $userCount    = User::count();
        $taskCount    = Task::count();
        $todayTask    = Task::whereTime('created_at', 'today')->count();
        $completedTask = Task::where('status', Task::STATUS_COMPLETED)->count();
        $failedTask   = Task::where('status', Task::STATUS_FAILED)->count();
        $pendingTask  = Task::where('status', Task::STATUS_PENDING)->count();
        $processing   = Task::where('status', Task::STATUS_PROCESSING)->count();

        // 收入统计
        $totalRevenue = Order::where('status', Order::STATUS_PAID)->sum('amount');
        $monthRevenue = Order::where('status', Order::STATUS_PAID)
            ->whereTime('paid_at', 'month')
            ->sum('amount');
        $todayRevenue = Order::where('status', Order::STATUS_PAID)
            ->whereTime('paid_at', 'today')
            ->sum('amount');

        // 新增用户
        $newUsersToday = User::whereTime('created_at', 'today')->count();
        $newUsersWeek  = User::whereTime('created_at', 'week')->count();

        // 任务语言分布
        $langDist = Task::field('language, count(*) as cnt')
            ->group('language')
            ->select()
            ->toArray();

        return $this->success([
            'users' => [
                'total'       => $userCount,
                'today_new'   => $newUsersToday,
                'week_new'    => $newUsersWeek,
            ],
            'tasks' => [
                'total'      => $taskCount,
                'today'      => $todayTask,
                'completed'  => $completedTask,
                'failed'     => $failedTask,
                'pending'    => $pendingTask,
                'processing' => $processing,
                'success_rate' => $taskCount > 0
                    ? round($completedTask / $taskCount * 100, 2) : 0,
            ],
            'revenue' => [
                'total' => round((float) $totalRevenue, 2),
                'month' => round((float) $monthRevenue, 2),
                'today' => round((float) $todayRevenue, 2),
            ],
            'lang_distribution' => $langDist,
            'server_time' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 用户列表
     * GET /admin/users
     */
    public function users()
    {
        $this->checkAdmin();
        [$page, $size] = $this->pageParams();
        $keyword = trim((string) $this->request->param('keyword', ''));
        $status  = $this->request->param('status', '');

        $query = new User();
        if ($keyword !== '') {
            $query = $query->where(function ($q) use ($keyword) {
                $q->whereLike('username', '%' . $keyword . '%')
                  ->whereOr('email', 'like', '%' . $keyword . '%')
                  ->whereOr('phone', 'like', '%' . $keyword . '%');
            });
        }
        if ($status !== '' && $status !== null) {
            $query = $query->where('status', (int) $status);
        }
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();

        return $this->success([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'page_size' => $size,
        ]);
    }

    /**
     * 用户详情
     * GET /admin/users/:id
     */
    public function userDetail($id)
    {
        $this->checkAdmin();
        $user = User::find((int) $id);
        if (!$user) {
            return $this->fail('用户不存在', 4001, null, 404);
        }
        $account = UserAccount::where('user_id', $user->id)->find();
        $taskStats = [
            'total'     => Task::where('user_id', $user->id)->count(),
            'completed' => Task::where('user_id', $user->id)->where('status', Task::STATUS_COMPLETED)->count(),
            'failed'    => Task::where('user_id', $user->id)->where('status', Task::STATUS_FAILED)->count(),
        ];
        $data = $user->toArray();
        $data['account']    = $account ? $account->toArray() : null;
        $data['task_stats'] = $taskStats;
        return $this->success($data);
    }

    /**
     * 启用/禁用用户
     * PUT /admin/users/:id/status
     */
    public function toggleUserStatus($id)
    {
        $this->checkAdmin();
        $user = User::find((int) $id);
        if (!$user) {
            return $this->fail('用户不存在', 4001, null, 404);
        }
        if ($user->isAdmin()) {
            return $this->fail('不能禁用管理员账号', 4002);
        }
        $newStatus = (int) $this->request->put('status', $user->status === 1 ? 0 : 1);
        if (!in_array($newStatus, [0, 1], true)) {
            return $this->fail('status参数无效', 4003);
        }
        $user->save(['status' => $newStatus]);
        // 禁用时清除登录态
        if ($newStatus === 0) {
            Cache::delete('jwt:refresh:' . $user->id);
        }
        return $this->success(['status' => $newStatus], $newStatus === 1 ? '已启用' : '已禁用');
    }

    /**
     * 所有任务列表
     * GET /admin/tasks
     */
    public function tasks()
    {
        $this->checkAdmin();
        [$page, $size] = $this->pageParams();
        $status   = $this->request->param('status', '');
        $language = $this->request->param('language', '');
        $userId   = (int) $this->request->param('user_id', 0);

        $query = Task::with(['user']);
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($language !== '') {
            $query->where('language', strtolower($language));
        }
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();

        return $this->success([
            'list'  => $list,
            'total' => $total,
            'page'  => $page,
            'page_size' => $size,
        ]);
    }

    /**
     * 任务详情
     * GET /admin/tasks/:id
     */
    public function taskDetail($id)
    {
        $this->checkAdmin();
        $task = Task::with(['user', 'account'])->find((int) $id);
        if (!$task) {
            return $this->fail('任务不存在', 4004, null, 404);
        }
        return $this->success($task->toArray());
    }

    /**
     * 套餐管理列表
     * GET /admin/plans
     */
    public function plans()
    {
        $this->checkAdmin();
        $list = Plan::order('sort_order', 'asc')->select()->toArray();
        return $this->success(['list' => $list, 'total' => count($list)]);
    }

    /**
     * 创建套餐
     * POST /admin/plans
     */
    public function createPlan()
    {
        $this->checkAdmin();
        $data = $this->validatePlan();
        $data['features'] = $data['features'] ?? Plan::defaultFeatures(1);
        $plan = Plan::create($data);
        return $this->success($plan->toArray(), '套餐已创建');
    }

    /**
     * 更新套餐
     * PUT /admin/plans/:id
     */
    public function updatePlan($id)
    {
        $this->checkAdmin();
        $plan = Plan::find((int) $id);
        if (!$plan) {
            return $this->fail('套餐不存在', 4005, null, 404);
        }
        $data = $this->validatePlan(false);
        $plan->save($data);
        return $this->success($plan->toArray(), '套餐已更新');
    }

    /**
     * 订单管理
     * GET /admin/orders
     */
    public function orders()
    {
        $this->checkAdmin();
        [$page, $size] = $this->pageParams();
        $status = $this->request->param('status', '');
        $query = Order::with(['user', 'plan']);
        if ($status !== '') {
            $query->where('status', $status);
        }
        $total = $query->count();
        $list = $query->order('id', 'desc')
            ->page($page, $size)
            ->select()
            ->toArray();
        $totalAmount = Order::where('status', Order::STATUS_PAID)->sum('amount');
        return $this->success([
            'list'         => $list,
            'total'        => $total,
            'page'         => $page,
            'page_size'    => $size,
            'total_revenue'=> round((float) $totalAmount, 2),
        ]);
    }

    /**
     * 审计日志查询
     * GET /admin/audit-logs
     */
    public function auditLogs()
    {
        $this->checkAdmin();
        [$page, $size] = $this->pageParams();
        $query = Db::name('audit_logs');
        $userId   = (int) $this->request->param('user_id', 0);
        $module   = $this->request->param('module', '');
        $startDay = $this->request->param('start_date', '');
        $endDay   = $this->request->param('end_date', '');
        if ($userId > 0) {
            $query->where('user_id', $userId);
        }
        if ($module !== '') {
            $query->where('module', $module);
        }
        if ($startDay !== '') {
            $query->whereTime('created_at', '>=', $startDay);
        }
        if ($endDay !== '') {
            $query->whereTime('created_at', '<=', $endDay . ' 23:59:59');
        }
        $total = $query->count();
        $list = $query->order('id', 'desc')
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
     * 系统配置
     * GET /admin/config
     */
    public function systemConfig()
    {
        $this->checkAdmin();
        return $this->success([
            'app' => [
                'name' => Config::get('app.name', 'CodeHardening'),
                'env'  => Config::get('app.env', 'production'),
                'url'  => Config::get('app.url', ''),
            ],
            'hardening' => [
                'languages'    => Config::get('hardening.languages', []),
                'max_file_size'=> (int) Config::get('hardening.max_file_size', 0),
                'task_timeout' => (int) Config::get('hardening.task_timeout', 300),
            ],
            'upload' => [
                'max_size'   => (int) Config::get('upload.max_size', 0),
                'extensions' => Config::get('upload.extensions', []),
            ],
            'rate_limit' => [
                'default' => (int) Config::get('rate_limit.default', 60),
                'upload'  => (int) Config::get('rate_limit.upload', 10),
            ],
            'audit_log' => [
                'enabled'   => (bool) Config::get('audit_log.enabled', true),
                'keep_days' => (int) Config::get('audit_log.keep_days', 90),
            ],
        ]);
    }

    /**
     * 校验管理员权限
     */
    private function checkAdmin(): void
    {
        $uid = $this->userId();
        if ($uid <= 0) {
            $this->json(401, '请先登录', null, 401)->send();
            exit;
        }
        $isAdmin = (int) ($this->request->header('X-Is-Admin') ?? 0);
        if ($isAdmin !== 1) {
            $user = User::find($uid);
            if (!$user || !$user->isAdmin()) {
                $this->json(403, '无管理员权限', null, 403)->send();
                exit;
            }
        }
    }

    /**
     * 套餐参数校验
     */
    private function validatePlan(bool $isCreate = true): array
    {
        $name        = trim((string) $this->request->post('name', ''));
        $description = trim((string) $this->request->post('description', ''));
        $price       = (float) $this->request->post('price', 0);
        $dailyQuota  = (int) $this->request->post('daily_quota', 0);
        $maxFileSize = (int) $this->request->post('max_file_size', 10485760);
        $features    = $this->request->post('features', []);
        $status      = (int) $this->request->post('status', Plan::STATUS_ENABLED);
        $sortOrder   = (int) $this->request->post('sort_order', 0);

        if ($isCreate && $name === '') {
            $this->json(1, '套餐名称不能为空')->send();
            exit;
        }
        if ($price < 0) {
            $this->json(1, '价格不能为负')->send();
            exit;
        }
        if ($dailyQuota < 0) {
            $this->json(1, '每日配额不能为负')->send();
            exit;
        }
        if (is_string($features)) {
            $features = json_decode($features, true) ?: [];
        }
        return [
            'name'         => $name,
            'description'  => $description,
            'price'        => $price,
            'daily_quota'  => $dailyQuota,
            'max_file_size'=> $maxFileSize,
            'features'     => $features,
            'status'       => $status,
            'sort_order'   => $sortOrder,
        ];
    }
}
