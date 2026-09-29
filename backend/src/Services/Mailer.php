<?php
namespace Services;

/**
 * 邮件发送服务 (PHPMailer + SMTP)
 * SMTP 配置可通过环境变量覆盖，方便在不同环境部署。
 */
class Mailer {
    /**
     * 是否模拟发送（不真正连接 SMTP）
     * 本地/演示环境可设置 MAIL_MOCK=1 开启，避免白屏且方便联调。
     */
    public static function isMock(): bool {
        return getenv('MAIL_MOCK') === '1' || getenv('MAIL_MOCK') === 'true';
    }

    /**
     * 发送授权更绑验证码。
     *
     * @param string $toEmail 收件邮箱（QQ邮箱）
     * @param string $code    6位验证码
     * @param int    $ttlMin  有效期(分钟)
     * @return array{success:bool, mock?:bool, error?:string}
     */
    public static function sendVerificationCode(string $toEmail, string $code, int $ttlMin = 10): array {
        // 模拟模式：不发真实邮件，直接成功（演示/联调用）
        if (self::isMock()) {
            error_log("[MAIL_MOCK] 验证码 {$code} -> {$toEmail}");
            return ['success' => true, 'mock' => true];
        }

        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return ['success' => false, 'error' => '邮件组件未安装，请检查后端依赖'];
        }

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $host     = getenv('MAIL_HOST') ?: 'smtp.163.com';
            $port     = (int)(getenv('MAIL_PORT') ?: 465);
            $username = getenv('MAIL_USERNAME') ?: 'yuwangifeng@163.com';
            $password = getenv('MAIL_PASSWORD') ?: 'LRZMA358wePVGa8F';
            $secure   = getenv('MAIL_SECURE') ?: 'ssl';

            $mail->isSMTP();
            $mail->SMTPAuth   = true;
            $mail->Host       = $host;
            $mail->Port       = $port;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->SMTPSecure = $secure === 'tls'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->CharSet    = 'UTF-8';
            // 只记录关键错误，避免 SMTPDebug 把调试信息写进响应
            $mail->SMTPDebug  = 0;

            // 容器内自签证书兼容
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom($username, '星罗授权系统');
            $mail->addAddress($toEmail);
            $mail->Hostname = 'localhost';

            $mail->isHTML(true);
            $mail->Subject = '【星罗授权系统】自助更绑验证码';
            $mail->Body    =
                '<div style="font-family:Microsoft YaHei,Arial,sans-serif;max-width:480px;margin:0 auto;'
                . 'padding:24px;border:1px solid #e5e7eb;border-radius:12px;color:#0f172a">'
                . '<h2 style="margin:0 0 16px;color:#0284c7">授权资料修改验证</h2>'
                . '<p style="line-height:1.8">您正在修改授权资料，本次验证码为：</p>'
                . '<p style="text-align:center;margin:20px 0"><span style="display:inline-block;font-size:30px;'
                . 'font-weight:bold;letter-spacing:8px;background:#f0f9ff;color:#0369a1;padding:12px 28px;'
                . 'border-radius:10px">' . htmlspecialchars($code, ENT_QUOTES) . '</span></p>'
                . '<p style="color:#64748b;font-size:13px;line-height:1.8">验证码 ' . $ttlMin
                . ' 分钟内有效，请勿泄露给他人。如非本人操作，请忽略此邮件。</p>'
                . '</div>';
            $mail->AltBody = "您的授权更绑验证码是：{$code}，{$ttlMin} 分钟内有效。如非本人操作请忽略。";

            $mail->send();
            return ['success' => true];
        } catch (\Throwable $e) {
            error_log('Mail send failed to ' . $toEmail . ': ' . $mail->ErrorInfo);
            return ['success' => false, 'error' => $mail->ErrorInfo ?: $e->getMessage()];
        }
    }
}
