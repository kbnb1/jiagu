<?php
declare(strict_types=1);

namespace app\api\controller;

use app\BaseController;
use app\common\traits\ApiResponse;
use app\common\model\User;
use app\common\model\UserAccount;
use app\common\model\Plan;
use app\common\service\JwtService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Config;
use think\exception\ValidateException;

/**
 * 用户API控制器
 */
class UserController extends BaseController
{
    use ApiResponse;

    /**
     * 用户注册
     * POST /api/user/register
     */
    public function register()
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        if (strlen($username) < 3 || strlen($username) > 32) {
            return $this->fail('用户名长度需为3-32位', 1001);
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return $this->fail('用户名仅支持字母数字下划线', 1002);
        }
        if (strlen($password) < 6 || strlen($password) > 64) {
            return $this->fail('密码长度需为6-64位', 1003);
        }

        $exists = User::where('username', $username)->find();
        if ($exists) {
            return $this->fail('用户名已被占用', 1004);
        }

        Db::startTrans();
        try {
            $user = User::create([
                'username'      => $username,
                'password_hash' => $password,
                'email'         => '',
                'phone'         => '',
                'avatar'        => '',
                'is_admin'      => 0,
                'status'        => 1,
            ]);
            // 为新用户创建账户并绑定免费套餐
            UserAccount::create([
                'user_id'        => $user->id,
                'plan_id'        => Plan::PLAN_FREE,
                'plan_expire_at' => null,
                'daily_quota'    => 3,
                'used_today'     => 0,
                'last_reset_date'=> date('Y-m-d'),
                'total_tasks'    => 0,
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            return $this->fail('注册失败: ' . $e->getMessage(), 1005);
        }

        return $this->success(['user_id' => $user->id], '注册成功');
    }

    /**
     * 用户登录
     * POST /api/user/login
     */
    public function login()
    {
        $username = trim((string) $this->request->post('username', ''));
        $password = (string) $this->request->post('password', '');

        if ($username === '' || $password === '') {
            return $this->fail('用户名和密码不能为空', 1011);
        }

        $user = User::where('username', $username)->find();
        if (!$user) {
            return $this->fail('用户名或密码错误', 1012);
        }
        if (!$user->verifyPassword($password)) {
            return $this->fail('用户名或密码错误', 1012);
        }
        if (!$user->isActive()) {
            return $this->fail('账号已被禁用,请联系管理员', 1013);
        }

        $claims = [
            'user_id'  => (int) $user->id,
            'username' => $user->username,
            'is_admin' => (int) $user->is_admin,
        ];
        $accessToken  = JwtService::makeAccessToken($claims);
        $refreshToken = JwtService::makeRefreshToken($claims);

        // 缓存 refresh_token 用于注销/校验
        $ttl = (int) Config::get('jwt.refresh_ttl', 2592000);
        Cache::set('jwt:refresh:' . $user->id, $refreshToken, $ttl);

        return $this->success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in'    => (int) Config::get('jwt.access_ttl', 604800),
            'token_type'    => 'Bearer',
            'user' => [
                'id'       => (int) $user->id,
                'username' => $user->username,
                'email'    => $user->email,
                'avatar'   => $user->avatar,
                'is_admin' => (int) $user->is_admin,
            ],
        ], '登录成功');
    }

    /**
     * 用户登出
     * POST /api/user/logout
     */
    public function logout()
    {
        $uid = $this->userId();
        if ($uid > 0) {
            Cache::delete('jwt:refresh:' . $uid);
            // 加入 JWT 黑名单，确保 token 无法再被使用
            $token = (string) $this->request->header('Authorization', '');
            $token = str_replace('Bearer ', '', $token);
            if ($token !== '' && Config::get('jwt.blacklist_enabled', true)) {
                JwtService::blacklistToken($token);
            }
        }
        return $this->success(null, '已退出登录');
    }

    /**
     * 刷新 token
     * POST /api/user/refresh
     */
    public function refresh()
    {
        $refreshToken = (string) $this->request->post('refresh_token', '');
        if ($refreshToken === '') {
            return $this->fail('refresh_token 不能为空', 1021);
        }
        $payload = JwtService::parse($refreshToken);
        if (!$payload || ($payload['typ'] ?? '') !== 'refresh') {
            return $this->fail('refresh_token 无效或已过期', 1022);
        }
        $uid = (int) ($payload['user_id'] ?? 0);
        if ($uid <= 0) {
            return $this->fail('refresh_token 无效', 1022);
        }
        // 校验是否与缓存中一致
        $cached = Cache::get('jwt:refresh:' . $uid);
        if ($cached !== $refreshToken) {
            return $this->fail('refresh_token 已失效,请重新登录', 1023);
        }
        $user = User::find($uid);
        if (!$user || !$user->isActive()) {
            return $this->fail('用户不存在或已禁用', 1024);
        }

        // 将旧 refresh_token 加入黑名单，防止重放
        JwtService::blacklistToken($refreshToken);

        $claims = [
            'user_id'  => (int) $user->id,
            'username' => $user->username,
            'is_admin' => (int) $user->is_admin,
        ];
        $accessToken  = JwtService::makeAccessToken($claims);
        $newRefresh   = JwtService::makeRefreshToken($claims);
        $ttl = (int) Config::get('jwt.refresh_ttl', 2592000);
        Cache::set('jwt:refresh:' . $uid, $newRefresh, $ttl);

        return $this->success([
            'access_token'  => $accessToken,
            'refresh_token' => $newRefresh,
            'expires_in'    => (int) Config::get('jwt.access_ttl', 604800),
            'token_type'    => 'Bearer',
        ], '刷新成功');
    }

    /**
     * 获取当前用户资料
     * GET /api/user/profile
     */
    public function profile()
    {
        $uid = $this->userId();
        $user = User::find($uid);
        if (!$user) {
            return $this->fail('用户不存在', 1031, null, 404);
        }
        $account = $user->account;
        $data = $user->toArray();
        $data['account'] = $account ? $account->toArray() : null;
        return $this->success($data);
    }

    /**
     * 更新用户资料
     * PUT /api/user/profile
     */
    public function updateProfile()
    {
        $uid = $this->userId();
        $user = User::find($uid);
        if (!$user) {
            return $this->fail('用户不存在', 1031, null, 404);
        }
        $email = trim((string) $this->request->put('email', $user->email));
        $phone = trim((string) $this->request->put('phone', $user->phone));
        $avatar = trim((string) $this->request->put('avatar', $user->avatar));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('邮箱格式不正确', 1041);
        }
        if ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return $this->fail('手机号格式不正确', 1042);
        }

        $user->save([
            'email'  => $email,
            'phone'  => $phone,
            'avatar' => $avatar,
        ]);
        return $this->success($user->toArray(), '资料更新成功');
    }

    /**
     * 修改密码
     * PUT /api/user/password
     */
    public function changePassword()
    {
        $uid = $this->userId();
        $user = User::find($uid);
        if (!$user) {
            return $this->fail('用户不存在', 1031, null, 404);
        }
        $oldPassword = (string) $this->request->put('old_password', '');
        $newPassword = (string) $this->request->put('new_password', '');

        if ($oldPassword === '' || $newPassword === '') {
            return $this->fail('原密码和新密码不能为空', 1051);
        }
        if (!$user->verifyPassword($oldPassword)) {
            return $this->fail('原密码错误', 1052);
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 64) {
            return $this->fail('新密码长度需为6-64位', 1053);
        }
        if ($oldPassword === $newPassword) {
            return $this->fail('新密码不能与原密码相同', 1054);
        }

        $user->save(['password_hash' => $newPassword]);
        // 修改密码后使旧token失效
        Cache::delete('jwt:refresh:' . $uid);
        return $this->success(null, '密码修改成功,请重新登录');
    }
}
