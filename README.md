# 星罗授权管理系统 (AuthQuery System)

## 🛠 技术栈
- Frontend: React + Vite + Tailwind CSS (Glassmorphism / Tech Blue)
- Backend: PHP 8.2 + Apache (MVC + PHPMailer)
- Database: MySQL 8.0

## 🚀 启动指南
1. 确保 Docker Desktop 已启动。
2. 在根目录执行：`docker compose up --build`
3. 访问: http://localhost:3891

## 🔗 服务说明
- **前端页面**: http://localhost:3891
- **API 接口**: http://localhost:8891
- **Mysql**: localhost:18891 (root/root)

## 🧪 管理员账号
- 登录地址: `/admin`
- 账号: `admin`
- 密码: `123456`

## ✨ 核心功能
1. **正版查询**: 动态极光背景，支持 QQ/主人 双重验证。
2. **自助更绑（邮箱验证码）**:
   - 先输入「授权 QQ + 所属产品」，校验授权存在后向绑定 QQ 邮箱（`QQ@qq.com`）发送 6 位验证码。
   - 验证码通过后才能修改「授权主人」或「联系邮箱」，二者至少修改一项。
   - 验证码 **10 分钟过期**；输错提示剩余次数，**连续输错 5 次**作废需重新获取。
   - 同一授权 **60 秒重发冷却**、**每小时最多 5 次**，频繁发送有明确提示。
   - 邮件发送失败时页面内给出可重试的错误提示，**不会白屏**；验证码为一次性，修改成功即失效。
   - SMTP 参数可用环境变量覆盖（见 `.env.example`）；联调/演示可设置 `MAIL_MOCK=1` 走模拟发信。
   - 若发送失败，请在 `docker logs auth_backend` 查看 SMTP 错误日志。
3. **后台管理**: 
   - 现代化表格设计 (头像/状态徽章)。
   - 自定义玻璃拟态弹窗 (Modal) 代替原生 Alert。
   - 完备的 CRUD 功能。

## 🔌 更绑相关接口
| 接口 | 方法 | 说明 |
| --- | --- | --- |
| `/api/license/send-code` | POST | 校验 QQ+产品 → 限频 → 发送验证码（失败返回 502 JSON） |
| `/api/license/verify-code` | POST | 校验验证码，通过后返回当前主人/联系邮箱 |
| `/api/license/update` | POST | 再次校验验证码并修改资料，成功后消费验证码 |

## 📝 交付文档
- `SELF_TEST.md`: 完整的自测报告与架构说明。
