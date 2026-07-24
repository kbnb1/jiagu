-- +----------------------------------------------------------------------
-- | 完整商用表结构 + 初始数据
-- | 数据库: code_hardening
-- | 默认管理员: admin / admin123 (登录后请立即修改密码)
-- +----------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 用户表
-- ----------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `username`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '用户名',
  `password_hash` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '密码hash',
  `email`         VARCHAR(128) NOT NULL DEFAULT '' COMMENT '邮箱',
  `phone`         VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '手机号',
  `avatar`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT '头像',
  `is_admin`      TINYINT      NOT NULL DEFAULT 0 COMMENT '是否管理员 0否1是',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '状态 0禁用 1正常',
  `created_at`    DATETIME     DEFAULT NULL COMMENT '创建时间',
  `updated_at`    DATETIME     DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_email` (`email`),
  KEY `idx_phone` (`phone`),
  KEY `idx_status_admin` (`status`, `is_admin`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ----------------------------
-- 用户账户表
-- ----------------------------
DROP TABLE IF EXISTS `user_accounts`;
CREATE TABLE `user_accounts` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '账户ID',
  `user_id`         BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `plan_id`         INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT '套餐ID',
  `plan_expire_at`  DATETIME        DEFAULT NULL COMMENT '套餐到期时间',
  `daily_quota`     INT             NOT NULL DEFAULT 3 COMMENT '每日配额,0为无限',
  `used_today`      INT             NOT NULL DEFAULT 0 COMMENT '今日已用次数',
  `last_reset_date` DATE            DEFAULT NULL COMMENT '上次重置日期',
  `total_tasks`     INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '累计任务数',
  `created_at`      DATETIME        DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_plan_id` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户账户表';

-- ----------------------------
-- 套餐表
-- ----------------------------
DROP TABLE IF EXISTS `plans`;
CREATE TABLE `plans` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '套餐ID',
  `name`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '套餐名称',
  `description`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT '套餐描述',
  `price`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '价格(元)',
  `daily_quota`   INT          NOT NULL DEFAULT 3 COMMENT '每日配额,0为无限',
  `max_file_size` BIGINT       NOT NULL DEFAULT 10485760 COMMENT '最大文件大小(字节)',
  `features`      JSON         DEFAULT NULL COMMENT '特性配置',
  `status`        TINYINT      NOT NULL DEFAULT 1 COMMENT '状态 0下架 1上架',
  `sort_order`    INT          NOT NULL DEFAULT 0 COMMENT '排序',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='套餐表';

-- ----------------------------
-- 加固任务表
-- ----------------------------
DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '任务ID',
  `user_id`      BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `task_no`      VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '任务编号',
  `language`     VARCHAR(20)  NOT NULL DEFAULT '' COMMENT '编程语言',
  `options`      JSON         DEFAULT NULL COMMENT '加固选项',
  `source_file`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '源文件路径',
  `result_file`  VARCHAR(255) NOT NULL DEFAULT '' COMMENT '结果文件路径',
  `status`       VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT '状态 pending/processing/completed/failed',
  `progress`     TINYINT      NOT NULL DEFAULT 0 COMMENT '进度0-100',
  `error_msg`    VARCHAR(500) DEFAULT NULL COMMENT '错误信息',
  `file_size`    BIGINT       NOT NULL DEFAULT 0 COMMENT '文件大小(字节)',
  `duration`     INT          NOT NULL DEFAULT 0 COMMENT '耗时(秒)',
  `created_at`   DATETIME     DEFAULT NULL COMMENT '创建时间',
  `completed_at` DATETIME     DEFAULT NULL COMMENT '完成时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_task_no` (`task_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_language` (`language`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='加固任务表';

-- ----------------------------
-- 订单表
-- ----------------------------
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '订单ID',
  `order_no`       VARCHAR(32)   NOT NULL DEFAULT '' COMMENT '订单号',
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `plan_id`        INT UNSIGNED  NOT NULL COMMENT '套餐ID',
  `amount`         DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `status`         VARCHAR(20)   NOT NULL DEFAULT 'pending' COMMENT '状态 pending/paid/cancelled/refunded',
  `payment_method` VARCHAR(20)   NOT NULL DEFAULT '' COMMENT '支付方式',
  `paid_at`        DATETIME      DEFAULT NULL COMMENT '支付时间',
  `expire_at`      DATETIME      DEFAULT NULL COMMENT '订单过期时间',
  `created_at`     DATETIME      DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_plan_id` (`plan_id`),
  KEY `idx_status` (`status`),
  KEY `idx_paid_at` (`paid_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';

-- ----------------------------
-- 审计日志表
-- ----------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户ID',
  `username`   VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '用户名',
  `module`     VARCHAR(32)  NOT NULL DEFAULT '' COMMENT '模块 user/task/plan/admin等',
  `action`     VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '操作',
  `method`     VARCHAR(10)  NOT NULL DEFAULT '' COMMENT '请求方法',
  `url`        VARCHAR(500) NOT NULL DEFAULT '' COMMENT '请求URL',
  `params`     TEXT         DEFAULT NULL COMMENT '请求参数',
  `ip`         VARCHAR(45)  NOT NULL DEFAULT '' COMMENT 'IP地址',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `status_code` INT         NOT NULL DEFAULT 200 COMMENT '响应状态码',
  `duration`   INT          NOT NULL DEFAULT 0 COMMENT '耗时(毫秒)',
  `created_at` DATETIME     DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='审计日志表';

-- ----------------------------
-- 初始数据: 套餐
-- ----------------------------
INSERT INTO `plans` (`id`, `name`, `description`, `price`, `daily_quota`, `max_file_size`, `features`, `status`, `sort_order`) VALUES
(1, '免费版', '每日3次加固,适合个人体验', 0.00, 3, 5242880, '{"languages":["php","javascript","python"],"max_obfuscate":1,"encrypt_strings":false,"control_flow":false,"priority_support":false}', 1, 1),
(2, '基础版', '每日50次加固,支持全部语言', 29.00, 50, 10485760, '{"languages":["php","java","javascript","python","cpp"],"max_obfuscate":2,"encrypt_strings":true,"control_flow":false,"priority_support":false}', 1, 2),
(3, '专业版', '每日500次加固,全功能支持', 99.00, 500, 52428800, '{"languages":["php","java","javascript","python","cpp"],"max_obfuscate":3,"encrypt_strings":true,"control_flow":true,"priority_support":true}', 1, 3),
(4, '企业版', '无限次数,专属技术支持', 999.00, 0, 104857600, '{"languages":["php","java","javascript","python","cpp"],"max_obfuscate":3,"encrypt_strings":true,"control_flow":true,"priority_support":true,"dedicated_support":true}', 1, 4);

-- ----------------------------
-- 初始数据: 管理员账号
-- 默认密码: admin123 (请在首次登录后立即修改)
-- 下方 password_hash 为 PHP password_hash('admin123', PASSWORD_DEFAULT) 生成
-- ----------------------------
INSERT INTO `users` (`id`, `username`, `password_hash`, `email`, `phone`, `avatar`, `is_admin`, `status`, `created_at`, `updated_at`) VALUES
(1, 'admin', '$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqK9XaQ2b8F6pQ3lVqYwJXqIuQDp7e', 'admin@hardening.local', '', '', 1, 1, NOW(), NOW());

INSERT INTO `user_accounts` (`user_id`, `plan_id`, `plan_expire_at`, `daily_quota`, `used_today`, `last_reset_date`, `total_tasks`, `created_at`) VALUES
(1, 4, NULL, 0, 0, CURDATE(), 0, NOW());

SET FOREIGN_KEY_CHECKS = 1;
