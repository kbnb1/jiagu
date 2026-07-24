-- +----------------------------------------------------------------------
-- | 基础表结构: users, user_accounts
-- | 数据库: code_hardening
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
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ----------------------------
-- 用户账户表
-- ----------------------------
DROP TABLE IF EXISTS `user_accounts`;
CREATE TABLE `user_accounts` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '账户ID',
  `user_id`        BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `plan_id`        INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT '套餐ID',
  `plan_expire_at` DATETIME        DEFAULT NULL COMMENT '套餐到期时间',
  `daily_quota`    INT             NOT NULL DEFAULT 3 COMMENT '每日配额,0为无限',
  `used_today`     INT             NOT NULL DEFAULT 0 COMMENT '今日已用次数',
  `last_reset_date` DATE           DEFAULT NULL COMMENT '上次重置日期',
  `total_tasks`    INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT '累计任务数',
  `created_at`     DATETIME        DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_id` (`user_id`),
  KEY `idx_plan_id` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户账户表';

SET FOREIGN_KEY_CHECKS = 1;
