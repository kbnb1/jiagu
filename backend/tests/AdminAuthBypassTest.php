<?php
/**
 * 管理员权限绕过漏洞测试
 *
 * 验证修复：确保 X-Is-Admin 头不能绕过管理员权限检查
 */

// 模拟请求对象
class MockRequest {
    private $headers = [];
    public $userId = 1;
    
    public function __construct(array $headers = []) {
        $this->headers = $headers;
    }
    
    public function header($name, $default = null) {
        return $this->headers[$name] ?? $default;
    }
}

// 模拟 User 模型
class MockUser {
    public $id;
    public $is_admin;
    
    public function __construct($id, $isAdmin = false) {
        $this->id = $id;
        $this->is_admin = $isAdmin ? 1 : 0;
    }
    
    public function isAdmin(): bool {
        return $this->is_admin === 1;
    }
}

/**
 * 修复前的漏洞代码（简化版）
 */
function checkAdminVulnerable($request, $user) {
    $uid = $request->userId;
    if ($uid <= 0) {
        return ['status' => 401, 'message' => '请先登录'];
    }
    
    // 漏洞：信任 X-Is-Admin 请求头
    $isAdmin = (int) ($request->header('X-Is-Admin') ?? 0);
    if ($isAdmin !== 1) {
        if (!$user || !$user->isAdmin()) {
            return ['status' => 403, 'message' => '无管理员权限'];
        }
    }
    
    return ['status' => 200, 'message' => 'OK'];
}

/**
 * 修复后的安全代码
 */
function checkAdminFixed($request, $user) {
    $uid = $request->userId;
    if ($uid <= 0) {
        return ['status' => 401, 'message' => '请先登录'];
    }
    
    // 修复：不信任请求头，直接从数据库查询
    if (!$user || !$user->isAdmin()) {
        return ['status' => 403, 'message' => '无管理员权限'];
    }
    
    return ['status' => 200, 'message' => 'OK'];
}

// ===== 测试用例 =====

echo "=== 管理员权限绕过漏洞测试 ===\n\n";

// 测试场景1：普通用户尝试通过伪造请求头获取管理员权限
$normalUser = new MockUser(1, false); // 普通用户
$requestWithFakeHeader = new MockRequest(['X-Is-Admin' => '1']);

echo "测试1: 普通用户 + 伪造 X-Is-Admin: 1 头\n";
echo "  修复前结果: ";
$result = checkAdminVulnerable($requestWithFakeHeader, $normalUser);
echo "HTTP {$result['status']} - {$result['message']}\n";
echo "  修复后结果: ";
$result = checkAdminFixed($requestWithFakeHeader, $normalUser);
echo "HTTP {$result['status']} - {$result['message']}\n";
echo "  " . ($result['status'] === 403 ? "✓ 漏洞已修复" : "✗ 修复失败") . "\n\n";

// 测试场景2：真实管理员（应始终通过）
$adminUser = new MockUser(2, true); // 管理员
$requestWithoutHeader = new MockRequest();

echo "测试2: 真实管理员 + 无伪造头\n";
echo "  修复前结果: ";
$result = checkAdminVulnerable($requestWithoutHeader, $adminUser);
echo "HTTP {$result['status']} - {$result['message']}\n";
echo "  修复后结果: ";
$result = checkAdminFixed($requestWithoutHeader, $adminUser);
echo "HTTP {$result['status']} - {$result['message']}\n";
echo "  " . ($result['status'] === 200 ? "✓ 正常管理员权限不受影响" : "✗ 权限检查异常") . "\n\n";

// 测试场景3：普通用户不伪造请求头（应被拒绝）
echo "测试3: 普通用户 + 无伪造头\n";
echo "  修复前结果: ";
$result = checkAdminVulnerable($requestWithoutHeader, $normalUser);
echo "HTTP {$result['status']} - {$result['message']}\n";
echo "  修复后结果: ";
$result = checkAdminFixed($requestWithoutHeader, $normalUser);
echo "HTTP {$result['status']} - {$result['message']}\n";
echo "  " . ($result['status'] === 403 ? "✓ 正常拒绝非管理员" : "✗ 权限检查异常") . "\n\n";

echo "=== 测试完成 ===\n";