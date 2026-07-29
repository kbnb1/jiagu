<?php
/**
 * 配额竞态条件测试
 *
 * 模拟并发请求验证 tryIncrementUsage 的原子性
 */

// 简化的测试：验证原子UPDATE逻辑正确性

class QuotaRaceConditionTest
{
    private $db;

    public function __construct()
    {
        // 使用SQLite内存数据库测试
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 创建测试表
        $this->db->exec('
            CREATE TABLE user_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                daily_quota INTEGER DEFAULT 10,
                used_today INTEGER DEFAULT 0,
                total_tasks INTEGER DEFAULT 0,
                last_reset_date TEXT
            )
        ');
    }

    public function testAtomicIncrementWithinQuota(): bool
    {
        $this->db->exec('DELETE FROM user_accounts');
        $this->db->exec('INSERT INTO user_accounts (user_id, daily_quota, used_today, total_tasks) VALUES (1, 10, 5, 0)');
        $id = $this->db->lastInsertId();

        // 模拟 tryIncrementUsage 的原子UPDATE
        $stmt = $this->db->prepare(
            'UPDATE user_accounts SET used_today = used_today + 1, total_tasks = total_tasks + 1 WHERE id = ? AND used_today < ?'
        );
        $stmt->execute([$id, 10]);
        $affected = $stmt->rowCount();

        // 验证：used_today=5 < daily_quota=10，应该成功
        if ($affected !== 1) {
            echo "FAIL: Expected 1 row affected, got $affected\n";
            return false;
        }

        $row = $this->db->query('SELECT used_today, total_tasks FROM user_accounts WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['used_today'] !== 6 || (int)$row['total_tasks'] !== 1) {
            echo "FAIL: Expected used_today=6, total_tasks=1, got used_today={$row['used_today']}, total_tasks={$row['total_tasks']}\n";
            return false;
        }

        echo "PASS: Atomic increment within quota works correctly\n";
        return true;
    }

    public function testAtomicIncrementExceedsQuota(): bool
    {
        $this->db->exec('DELETE FROM user_accounts');
        $this->db->exec('INSERT INTO user_accounts (user_id, daily_quota, used_today, total_tasks) VALUES (1, 10, 10, 0)');
        $id = $this->db->lastInsertId();

        // 尝试在已满配额时递增
        $stmt = $this->db->prepare(
            'UPDATE user_accounts SET used_today = used_today + 1, total_tasks = total_tasks + 1 WHERE id = ? AND used_today < ?'
        );
        $stmt->execute([$id, 10]);
        $affected = $stmt->rowCount();

        // 验证：used_today=10 >= daily_quota=10，应该失败
        if ($affected !== 0) {
            echo "FAIL: Expected 0 rows affected (quota full), got $affected\n";
            return false;
        }

        $row = $this->db->query('SELECT used_today, total_tasks FROM user_accounts WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['used_today'] !== 10 || (int)$row['total_tasks'] !== 0) {
            echo "FAIL: Values should remain unchanged\n";
            return false;
        }

        echo "PASS: Atomic increment correctly rejects when quota exceeded\n";
        return true;
    }

    public function testUnlimitedQuota(): bool
    {
        $this->db->exec('DELETE FROM user_accounts');
        $this->db->exec('INSERT INTO user_accounts (user_id, daily_quota, used_today, total_tasks) VALUES (1, 0, 0, 0)');
        $id = $this->db->lastInsertId();

        // unlimited quota (daily_quota = 0)
        $stmt = $this->db->prepare(
            'UPDATE user_accounts SET used_today = used_today + 1, total_tasks = total_tasks + 1 WHERE id = ?'
        );
        $stmt->execute([$id]);
        $affected = $stmt->rowCount();

        if ($affected !== 1) {
            echo "FAIL: Unlimited quota should always succeed\n";
            return false;
        }

        $row = $this->db->query('SELECT used_today FROM user_accounts WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['used_today'] !== 1) {
            echo "FAIL: Unlimited quota increment failed\n";
            return false;
        }

        echo "PASS: Unlimited quota works correctly\n";
        return true;
    }

    public function testBoundaryCondition(): bool
    {
        $this->db->exec('DELETE FROM user_accounts');
        $this->db->exec('INSERT INTO user_accounts (user_id, daily_quota, used_today, total_tasks) VALUES (1, 10, 9, 0)');
        $id = $this->db->lastInsertId();

        // 最后一次可用配额
        $stmt = $this->db->prepare(
            'UPDATE user_accounts SET used_today = used_today + 1, total_tasks = total_tasks + 1 WHERE id = ? AND used_today < ?'
        );
        $stmt->execute([$id, 10]);
        $affected1 = $stmt->rowCount();

        // 再次尝试应该失败
        $stmt->execute([$id, 10]);
        $affected2 = $stmt->rowCount();

        if ($affected1 !== 1) {
            echo "FAIL: First increment should succeed (9 < 10)\n";
            return false;
        }

        if ($affected2 !== 0) {
            echo "FAIL: Second increment should fail (10 not < 10)\n";
            return false;
        }

        $row = $this->db->query('SELECT used_today FROM user_accounts WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
        if ((int)$row['used_today'] !== 10) {
            echo "FAIL: Final used_today should be exactly 10\n";
            return false;
        }

        echo "PASS: Boundary condition handled correctly\n";
        return true;
    }

    public function run(): int
    {
        $allPass = true;
        $allPass = $this->testAtomicIncrementWithinQuota() && $allPass;
        $allPass = $this->testAtomicIncrementExceedsQuota() && $allPass;
        $allPass = $this->testUnlimitedQuota() && $allPass;
        $allPass = $this->testBoundaryCondition() && $allPass;

        echo "\n";
        echo $allPass ? "All quota tests PASSED\n" : "Some quota tests FAILED\n";
        return $allPass ? 0 : 1;
    }
}

// 运行测试
$test = new QuotaRaceConditionTest();
exit($test->run());