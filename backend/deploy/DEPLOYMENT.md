# 代码加固平台 - 部署文档

本文档描述 PHP 代码加固平台后端的完整部署流程。基于 ThinkPHP 架构,使用 PHP-FPM + Nginx + MySQL + Redis。

## 一、系统要求

| 组件 | 最低版本 | 推荐版本 | 说明 |
|------|---------|---------|------|
| 操作系统 | CentOS 7 / Ubuntu 18.04 | Ubuntu 22.04 LTS | 64位 Linux |
| PHP | 8.0 | 8.2+ | 需 CLI + FPM |
| MySQL | 5.7 | 8.0 | 字符集 utf8mb4 |
| Redis | 5.0 | 7.0+ | 缓存与队列 |
| Nginx | 1.18 | 1.24+ | Web 服务器 |
| Composer | 2.0 | 2.6+ | PHP 依赖管理 |

### 必需 PHP 扩展
```
php-fpm php-cli php-mysql php-redis php-mbstring php-json php-xml
php-curl php-gd php-fileinfo php-bcmath php-opcache php-zip php-pdo
```

## 二、安装步骤

### 2.1 克隆代码
```bash
mkdir -p /data/www
cd /data/www
git clone <repository-url> hardening
cd hardening/backend
```

### 2.2 安装依赖
```bash
composer install --no-dev --optimize-autoloader
```

### 2.3 配置环境变量
```bash
cp .env.example .env
# 编辑 .env,务必修改以下项:
#   APP_KEY          生成: php think key:gen
#   DB_*             数据库连接信息
#   REDIS_*          Redis 连接信息
#   JWT_SECRET       生成: openssl rand -hex 32
#   HARDENING_ENCRYPT_KEY 生成: openssl rand -hex 16
vi .env
```

### 2.4 设置目录权限
Web 运行用户通常为 `www` 或 `www-data`,需保证对以下目录可写:
```bash
# 假设 web 用户为 www
chown -R www:www /data/www/hardening/backend
chmod -R 755 /data/www/hardening/backend

# 运行时目录
mkdir -p runtime/{cache,log,uploads,tmp,sessions}
chown -R www:www runtime

# 上传目录
mkdir -p /data/uploads
chown -R www:www /data/uploads

# 加固临时目录
mkdir -p /tmp/hardening
chown -R www:www /tmp/hardening
```

## 三、数据库初始化

### 3.1 创建数据库
```sql
CREATE DATABASE code_hardening DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'hardening'@'%' IDENTIFIED BY 'YourStrongPassword';
GRANT ALL PRIVILEGES ON code_hardening.* TO 'hardening'@'%';
FLUSH PRIVILEGES;
```

### 3.2 导入表结构
```bash
# 商用环境(完整表 + 初始数据)
mysql -uhardening -p code_hardening < sql/commercial.sql

# 或仅基础表(开发环境)
mysql -uhardening -p code_hardening < sql/schema.sql
```

### 3.3 数据迁移(可选)
```bash
php think migrate:run
php think seed:run
```

## 四、Nginx 配置

参考 `deploy/nginx.conf.example`,复制并按实际域名/路径修改:
```bash
cp deploy/nginx.conf.example /etc/nginx/conf.d/hardening.conf
nginx -t && systemctl reload nginx
```

## 五、队列 Worker 配置(Supervisor)

加固任务通过异步队列处理,需常驻 worker 进程。

### 5.1 安装 Supervisor
```bash
# Ubuntu/Debian
apt-get install -y supervisor

# CentOS/RHEL
yum install -y supervisor
```

### 5.2 配置文件
创建 `/etc/supervisor/conf.d/hardening-worker.conf`:
```ini
[program:hardening-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /data/www/hardening/backend/think hardening:worker
autostart=true
autorestart=true
user=www
numprocs=4
redirect_stderr=true
stdout_logfile=/data/www/hardening/backend/runtime/log/worker.log
stopwaitsecs=3600
```

### 5.3 启动
```bash
supervisorctl reread
supervisorctl update
supervisorctl start hardening-worker:*
supervisorctl status
```

## 六、定时任务(Crontab)

用于每日配额重置、日志清理等定时任务。

```bash
crontab -e -u www
```

添加以下内容(路径按实际修改):
```cron
# 每分钟执行 ThinkPHP 计划任务
* * * * * cd /data/www/hardening/backend && php think schedule:run >> /dev/null 2>&1

# 每天 00:05 清理过期审计日志(保留 90 天)
5 0 * * * cd /data/www/hardening/backend && php think audit:cleanup >> runtime/log/cron.log 2>&1

# 每周日凌晨备份任务记录
0 3 * * 0 cd /data/www/hardening/backend && php think backup:tasks >> runtime/log/cron.log 2>&1
```

## 七、安全建议

1. **修改默认密码**:管理员初始密码 `admin123`,首次登录后立即修改。
2. **HTTPS**:生产环境必须启用 HTTPS,使用 Let's Encrypt 或商业证书。
3. **防火墙**:仅开放 80/443 端口,MySQL/Redis 不对外暴露。
4. **文件权限**:`.env` 文件权限设为 `600`,禁止 Web 访问。
5. **密钥保护**:`JWT_SECRET`、`HARDENING_ENCRYPT_KEY` 定期轮换。
6. **上传隔离**:上传目录禁止执行 PHP(Nginx 配置已处理)。
7. **数据库备份**:每日全量备份 + binlog 增量备份。
8. **访问日志**:开启 Nginx access log,定期审计异常请求。
9. **限制 SSH**:禁用 root 远程登录,使用密钥登录。
10. **依赖更新**:定期 `composer update` 修复安全漏洞。

## 八、常见问题

### Q1: 上传文件报 413 Request Entity Too Large
A: Nginx `client_max_body_size` 设置过小,需大于 `upload.max_size`(默认10MB),建议设为 12M。同时调整 PHP `upload_max_filesize` 与 `post_max_size`。

### Q2: 任务一直处于 pending 状态
A: 检查队列 worker 是否运行 `supervisorctl status`,查看 `runtime/log/worker.log` 是否有报错。Redis 连接需正常。

### Q3: 登录返回 401
A: 检查请求头是否携带 `Authorization: Bearer <token>`,token 是否过期,用户是否被禁用。

### Q4: Redis 连接失败
A: 确认 Redis 服务运行 `systemctl status redis`,`.env` 中 `REDIS_HOST/PORT/PASSWORD` 正确,防火墙放行。

### Q5: 数据库连接拒绝
A: 检查 MySQL `bind-address`、用户授权主机、`.env` 数据库配置、端口是否放行。

### Q6: 权限不足(写入失败)
A: `runtime`、`/data/uploads`、`/tmp/hardening` 目录需 PHP-FPM 运行用户(www)可写。

### Q7: JWT 解析失败
A: 确认 `JWT_SECRET` 与签发时一致,服务器时间是否准确 `ntpdate`。

### Q8: composer install 失败
A: 检查 PHP 版本及扩展是否齐全,内存限制 `COMPOSER_MEMORY_LIMIT=-1 composer install`。

## 九、部署检查清单

- [ ] PHP 版本及扩展已安装
- [ ] Composer 依赖已安装
- [ ] `.env` 已配置且密钥已替换
- [ ] 数据库已创建并导入表结构
- [ ] Redis 服务正常
- [ ] 目录权限已设置(runtime/upload/tmp)
- [ ] Nginx 配置已生效
- [ ] HTTPS 证书已配置
- [ ] Supervisor worker 已启动
- [ ] Crontab 已配置
- [ ] 默认管理员密码已修改
- [ ] 健康检查 `curl https://your-domain/api/health/status` 返回 healthy
