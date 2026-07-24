# 代码加固平台 (Code Hardening Platform)

企业级代码加密混淆 SaaS 平台，支持 PHP / Java / JavaScript / Python / C++ 多语言源码加固，提供 Android 客户端和 PHP 后端完整解决方案。

## 核心功能

### 代码加固引擎
- **多语言支持**：PHP、Java、JavaScript、Python、C/C++
- **变量/函数名混淆**：随机映射表，确保同一次加固内一致
- **字符串加密**：AES-256-CBC 加密，运行时动态解密
- **注释清除**：自动移除所有注释（保留 license 头）
- **控制流平坦化**：插入垃圾分支，增加逆向难度
- **反调试检测**：检测 xdebug / debugger / 调试工具并终止运行
- **空白压缩**：去除多余空白字符，减小体积
- **死代码注入**：插入无意义代码块干扰分析

### 后端服务 (PHP)
- JWT 双 Token 认证（access_token + refresh_token）
- 任务队列异步处理（Redis / 文件队列）
- 用户管理 + 套餐订阅 + 订单系统
- 管理后台（用户/任务/套餐/订单/审计日志）
- 中间件：CORS / 频率限制 / 审计日志 / 权限校验
- 文件上传下载（最大 10MB）

### Android 客户端
- 启动页 + 引导页 + 登录注册
- 文件选择 → 语言选择 → 加固选项 → 上传 → 轮询状态 → 下载
- 本地历史记录（SQLite）
- 套餐查看与订阅
- 个人中心（资料/密码/订单）
- 安全检测（Root / 模拟器检测）
- AES 本地加密存储

## 项目结构

```
├── app/                          # Android 客户端
│   ├── build.gradle              # 构建配置
│   ├── proguard-rules.pro        # ProGuard 混淆规则
│   └── src/main/
│       ├── AndroidManifest.xml
│       ├── java/com/hardening/app/
│       │   ├── HardeningApp.java       # Application 入口
│       │   ├── db/                      # 本地数据库
│       │   ├── model/                   # 数据模型
│       │   ├── network/                 # 网络层（Retrofit + OkHttp）
│       │   ├── security/                # 安全模块
│       │   └── ui/                      # 页面
│       └── res/                         # 布局与资源
│
└── backend/                      # PHP 后端
    ├── app/
    │   ├── admin/controller/     # 管理后台控制器
    │   ├── api/controller/       # API 控制器
    │   ├── command/              # 命令行工具
    │   └── common/
    │       ├── controller/       # 基础控制器
    │       ├── hardener/         # 加固引擎（核心）
    │       ├── middleware/       # 中间件
    │       ├── model/            # 数据模型
    │       ├── service/          # 服务层
    │       └── traits/           # 复用 trait
    ├── config/                   # 配置文件
    ├── deploy/                   # 部署文档
    ├── route/                    # 路由定义
    └── sql/                      # 数据库脚本
```

## 套餐方案

| 套餐 | 价格 | 每日额度 | 最大文件 |
|------|------|---------|---------|
| 免费版 | ¥0 | 3 次 | 1MB |
| 基础版 | ¥29/月 | 50 次 | 5MB |
| 专业版 | ¥99/月 | 500 次 | 10MB |
| 企业版 | ¥299/月 | 不限 | 10MB |

## 快速部署

### 后端
```bash
cd backend
cp .env.example .env
# 编辑 .env 配置数据库和 Redis
mysql -u root -p < sql/commercial.sql
php think hardening:worker  # 启动队列 Worker
```

详见 [backend/deploy/DEPLOYMENT.md](backend/deploy/DEPLOYMENT.md)

### Android
```bash
# 修改 HardeningApp.java 中的 BASE_URL 为你的后端地址
# 使用 Android Studio 打开项目编译即可
```

## 技术栈

- **后端**：PHP 8.0+ / MySQL / Redis
- **Android**：Java / Retrofit / OkHttp / Gson / Material Design
- **安全**：AES-256-CBC / JWT / PBKDF2 / ProGuard
