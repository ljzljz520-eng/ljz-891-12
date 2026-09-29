<?php
namespace Controllers;

use PDO;
use Services\Mailer;

class LicenseController {
    private $db;

    /** 验证码有效期(分钟) */
    private const CODE_TTL_MIN = 10;
    /** 同一授权重发冷却(秒) */
    private const RESEND_COOLDOWN_SEC = 60;
    /** 每小时最多发送条数 */
    private const HOURLY_LIMIT = 5;
    /** 验证码最多错误尝试次数 */
    private const MAX_ATTEMPTS = 5;

    public function __construct($db) {
        $this->db = $db;
    }

    /* ------------------------------- 工具方法 ------------------------------- */

    private function respond($data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function body(): array {
        $raw = json_decode(file_get_contents("php://input"), true);
        return is_array($raw) ? $raw : [];
    }

    /** 按 QQ + 产品精确查找授权（可能有多条产品记录，取最新一条） */
    private function findLicense(string $qq, string $product): ?array {
        $stmt = $this->db->prepare(
            "SELECT * FROM licenses WHERE qq = :qq AND product_name = :product
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':qq' => $qq, ':product' => $product]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** 邮箱脱敏，用于前端展示 */
    private function maskEmail(string $email): string {
        $parts = explode('@', $email);
        if (count($parts) !== 2) return $email;
        [$name, $domain] = $parts;
        $len = mb_strlen($name);
        if ($len <= 2) {
            $masked = substr($name, 0, 1) . str_repeat('*', max(1, $len - 1));
        } else {
            $masked = substr($name, 0, 2) . str_repeat('*', max(1, $len - 3)) . substr($name, -1);
        }
        return $masked . '@' . $domain;
    }

    /**
     * 校验验证码（不消费）。
     * 返回 ['ok'=>true,'code_row'=>...] 或 ['ok'=>false,'error'=>...,'message'=>...]
     */
    private function checkCode(int $licenseId, string $email, string $code): array {
        if (!preg_match('/^\d{6}$/', $code)) {
            return ['ok' => false, 'error' => 'invalid_format', 'message' => '请输入6位数字验证码'];
        }

        $stmt = $this->db->prepare(
            "SELECT * FROM verification_codes
             WHERE license_id = :lid AND identifier = :email AND used = 0
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':lid' => $licenseId, ':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return ['ok' => false, 'error' => 'no_code',
                'message' => '请先获取验证码'];
        }

        if (strtotime($row['expires_at']) <= time()) {
            return ['ok' => false, 'error' => 'expired',
                'message' => '验证码已过期，请重新获取'];
        }

        if ((int)$row['attempts'] >= self::MAX_ATTEMPTS) {
            return ['ok' => false, 'error' => 'too_many_attempts',
                'message' => '验证码错误次数过多，请重新获取'];
        }

        if (!hash_equals((string)$row['code'], $code)) {
            $attempts = (int)$row['attempts'] + 1;
            $upd = $this->db->prepare("UPDATE verification_codes SET attempts = :a WHERE id = :id");
            $upd->execute([':a' => $attempts, ':id' => $row['id']]);

            if ($attempts >= self::MAX_ATTEMPTS) {
                return ['ok' => false, 'error' => 'too_many_attempts',
                    'message' => '验证码错误次数过多，请重新获取'];
            }
            return ['ok' => false, 'error' => 'wrong_code',
                'message' => '验证码不正确，剩余可尝试 ' . (self::MAX_ATTEMPTS - $attempts) . ' 次'];
        }

        return ['ok' => true, 'code_row' => $row];
    }

    /* --------------------------------- 查询 --------------------------------- */

    public function query() {
        if (!isset($_GET['qq']) || !isset($_GET['owner'])) {
            $this->respond(["status" => "error", "message" => "缺少查询参数"], 400);
            return;
        }

        $qq = trim($_GET['qq']);
        $owner = trim($_GET['owner']);

        $query = "SELECT * FROM licenses WHERE qq = :qq AND owner_name = :owner ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":qq", $qq);
        $stmt->bindParam(":owner", $owner);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->respond([
                "status" => "success",
                "data" => [
                    "qq"            => $row['qq'],
                    "owner"         => $row['owner_name'],
                    "product"       => $row['product_name'],
                    "upline"        => $row['upline'],
                    "contact_email" => $row['contact_email'],
                    "expiration"    => $row['expiration_date'],
                    "created_at"    => $row['created_at']
                ]
            ]);
        } else {
            $this->respond([
                "status" => "error",
                "message" => "暂未查询到您的授权信息 请查证后再次查询！",
                "reasons" => [
                    "1.授权开通不足60分钟内",
                    "2.未购买正版授权，可能是盗版程序授权",
                    "3.恭喜你，被圈钱了！"
                ]
            ], 404);
        }
    }

    /* ------------------------------ 后台 CRUD ------------------------------- */

    public function listAll() {
        $query = "SELECT * FROM licenses ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    }

    public function create() {
        $data = $this->body();
        $query = "INSERT INTO licenses (qq, owner_name, product_name, upline, contact_email, expiration_date)
                  VALUES (:qq, :owner, :product, :upline, :email, :exp)";
        $stmt = $this->db->prepare($query);

        $params = [
            ":qq"      => $data['qq'] ?? '',
            ":owner"   => $data['owner_name'] ?? '',
            ":product" => $data['product_name'] ?? '',
            ":upline"  => $data['upline'] ?? '',
            ":email"   => !empty($data['contact_email']) ? $data['contact_email'] : null,
            ":exp"     => $data['expiration_date'] ?? null
        ];

        if ($stmt->execute($params)) {
            echo json_encode(["message" => "Created successfully"]);
        } else {
            $this->respond(["message" => "Create failed"], 500);
        }
    }

    public function delete() {
        $data = $this->body();
        if (!isset($data['id'])) {
            $this->respond(["message" => "缺少 id"], 400);
            return;
        }
        $query = "DELETE FROM licenses WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":id", $data['id']);
        $stmt->execute();
        echo json_encode(["message" => "Deleted"]);
    }

    /* --------------------------- 自助更绑：发送验证码 -------------------------- */

    public function sendVerificationCode() {
        try {
            $data    = $this->body();
            $qq      = trim((string)($data['qq'] ?? ''));
            $product = trim((string)($data['product_name'] ?? ''));

            if (!preg_match('/^[1-9]\d{4,11}$/', $qq)) {
                $this->respond(['status' => 'error', 'error' => 'invalid_qq',
                    'message' => '请输入正确的授权QQ号（5-12位数字）'], 400);
                return;
            }
            if ($product === '' || mb_strlen($product) > 100) {
                $this->respond(['status' => 'error', 'error' => 'invalid_product',
                    'message' => '请输入所属产品名称'], 400);
                return;
            }

            $license = $this->findLicense($qq, $product);
            if (!$license) {
                $this->respond(['status' => 'error', 'error' => 'license_not_found',
                    'message' => '未找到该QQ在该产品下的授权信息，请核对QQ与所属产品'], 404);
                return;
            }

            $email = $qq . '@qq.com';

            // 1) 冷却限制：60 秒内不可重复发送
            $stmt = $this->db->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed, id
                 FROM verification_codes
                 WHERE license_id = :lid AND identifier = :email AND used = 0
                 ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([':lid' => $license['id'], ':email' => $email]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($last && (int)$last['elapsed'] < self::RESEND_COOLDOWN_SEC) {
                $wait = self::RESEND_COOLDOWN_SEC - (int)$last['elapsed'];
                $this->respond(['status' => 'error', 'error' => 'cooldown',
                    'message' => "发送过于频繁，请 {$wait} 秒后再试", 'retry_after' => $wait], 429);
                return;
            }

            // 2) 每小时条数限制
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS cnt FROM verification_codes
                 WHERE license_id = :lid AND identifier = :email AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            $stmt->execute([':lid' => $license['id'], ':email' => $email]);
            if ((int)$stmt->fetchColumn() >= self::HOURLY_LIMIT) {
                $this->respond(['status' => 'error', 'error' => 'rate_limited',
                    'message' => '该授权每小时最多发送 ' . self::HOURLY_LIMIT . ' 次验证码，请稍后再试'], 429);
                return;
            }

            $code = (string)random_int(100000, 999999);

            $this->db->beginTransaction();
            try {
                // 作废旧验证码
                $invalidate = $this->db->prepare(
                    "UPDATE verification_codes SET used = 1
                     WHERE license_id = :lid AND identifier = :email AND used = 0"
                );
                $invalidate->execute([':lid' => $license['id'], ':email' => $email]);

                $insert = $this->db->prepare(
                    "INSERT INTO verification_codes
                        (type, identifier, license_id, code, used, attempts, expires_at)
                     VALUES ('update_license', :email, :lid, :code, 0, 0,
                        DATE_ADD(NOW(), INTERVAL " . (int)self::CODE_TTL_MIN . " MINUTE))"
                );
                $insert->execute([':email' => $email, ':lid' => $license['id'], ':code' => $code]);
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }

            // 3) 真正发送邮件（失败时返回结构化错误，绝不白屏）
            $result = Mailer::sendVerificationCode($email, $code, self::CODE_TTL_MIN);

            if (empty($result['success'])) {
                // 发信失败：作废刚生成的验证码，避免产生永远无法收到的死码
                $cleanup = $this->db->prepare(
                    "UPDATE verification_codes SET used = 1
                     WHERE license_id = :lid AND identifier = :email AND code = :code"
                );
                $cleanup->execute([':lid' => $license['id'], ':email' => $email, ':code' => $code]);

                $this->respond([
                    'status'  => 'error',
                    'error'   => 'mail_failed',
                    'message' => '验证码邮件发送失败，请稍后重试或联系管理员',
                    'detail'  => $result['error'] ?? '未知邮件错误',
                ], 502);
                return;
            }

            $this->respond([
                'status'       => 'success',
                'message'      => empty($result['mock'])
                    ? "验证码已发送至绑定QQ邮箱 {$this->maskEmail($email)}，" . self::CODE_TTL_MIN . ' 分钟内有效'
                    : '模拟模式：验证码已生成（未真正发信），' . self::CODE_TTL_MIN . ' 分钟内有效',
                'mock'         => !empty($result['mock']),
                'mock_code'    => !empty($result['mock']) ? $code : null,
                'masked_email' => $this->maskEmail($email),
                'expires_in'   => self::CODE_TTL_MIN * 60,
            ]);
        } catch (\Throwable $e) {
            error_log('sendVerificationCode error: ' . $e->getMessage());
            $this->respond(['status' => 'error', 'error' => 'server_error',
                'message' => '发送验证码时服务异常，请稍后重试'], 500);
        }
    }

    /* -------------------------- 自助更绑：验证码预检 --------------------------- */

    public function verifyCode() {
        try {
            $data = $this->body();
            $qq      = trim((string)($data['qq'] ?? ''));
            $product = trim((string)($data['product_name'] ?? ''));
            $code    = trim((string)($data['code'] ?? ''));

            if (!preg_match('/^[1-9]\d{4,11}$/', $qq) || $product === '') {
                $this->respond(['status' => 'error', 'error' => 'invalid_params',
                    'message' => '授权信息不完整，请返回上一步重新填写'], 400);
                return;
            }

            $license = $this->findLicense($qq, $product);
            if (!$license) {
                $this->respond(['status' => 'error', 'error' => 'license_not_found',
                    'message' => '未找到该QQ在该产品下的授权信息'], 404);
                return;
            }

            $email = $qq . '@qq.com';
            $check = $this->checkCode((int)$license['id'], $email, $code);
            if (!$check['ok']) {
                $status = $check['error'] === 'wrong_code' ? 400
                    : ($check['error'] === 'no_code' ? 400 : 410);
                if ($check['error'] === 'too_many_attempts') $status = 429;
                $this->respond(['status' => 'error', 'error' => $check['error'],
                    'message' => $check['message']], $status);
                return;
            }

            $this->respond([
                'status'  => 'success',
                'message' => '验证通过，请修改授权资料',
                'data'    => [
                    'owner'         => $license['owner_name'],
                    'contact_email' => $license['contact_email'],
                    'masked_email'  => $this->maskEmail($email),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('verifyCode error: ' . $e->getMessage());
            $this->respond(['status' => 'error', 'error' => 'server_error',
                'message' => '验证服务异常，请稍后重试'], 500);
        }
    }

    /* --------------------------- 自助更绑：确认修改 --------------------------- */

    public function update() {
        try {
            $data    = $this->body();
            $qq      = trim((string)($data['qq'] ?? ''));
            $product = trim((string)($data['product_name'] ?? ''));
            $code    = trim((string)($data['code'] ?? ''));
            $owner   = isset($data['owner_name']) ? trim($data['owner_name']) : null;
            $email   = isset($data['contact_email']) ? trim($data['contact_email']) : null;

            if (!preg_match('/^[1-9]\d{4,11}$/', $qq) || $product === '') {
                $this->respond(['status' => 'error', 'error' => 'invalid_params',
                    'message' => '授权信息不完整，请重新发起更绑'], 400);
                return;
            }

            // 至少要改一项
            $changeOwner = $owner !== null && $owner !== '';
            $changeEmail = $email !== null && $email !== '';
            if (!$changeOwner && !$changeEmail) {
                $this->respond(['status' => 'error', 'error' => 'nothing_to_update',
                    'message' => '请至少填写一项需要修改的内容'], 400);
                return;
            }
            if ($changeOwner && mb_strlen($owner) > 50) {
                $this->respond(['status' => 'error', 'error' => 'invalid_owner',
                    'message' => '授权主人名称过长（最多50字）'], 400);
                return;
            }
            if ($changeEmail) {
                if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->respond(['status' => 'error', 'error' => 'invalid_email',
                        'message' => '请输入正确的联系邮箱'], 400);
                    return;
                }
            }

            $license = $this->findLicense($qq, $product);
            if (!$license) {
                $this->respond(['status' => 'error', 'error' => 'license_not_found',
                    'message' => '未找到该QQ在该产品下的授权信息'], 404);
                return;
            }

            $boundEmail = $qq . '@qq.com';
            $check = $this->checkCode((int)$license['id'], $boundEmail, $code);
            if (!$check['ok']) {
                $status = $check['error'] === 'wrong_code' ? 400
                    : ($check['error'] === 'no_code' ? 400 : 410);
                if ($check['error'] === 'too_many_attempts') $status = 429;
                $this->respond(['status' => 'error', 'error' => $check['error'],
                    'message' => $check['message']], $status);
                return;
            }

            // 执行修改并消费验证码
            $this->db->beginTransaction();
            try {
                $sql = "UPDATE licenses SET ";
                $sets = [];
                $params = [':id' => $license['id']];
                if ($changeOwner) {
                    $sets[] = "owner_name = :owner";
                    $params[':owner'] = $owner;
                }
                if ($changeEmail) {
                    $sets[] = "contact_email = :email";
                    $params[':email'] = $email;
                }
                $sql .= implode(', ', $sets) . " WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);

                // 验证码一次性消费
                $done = $this->db->prepare("UPDATE verification_codes SET used = 1 WHERE id = :id");
                $done->execute([':id' => $check['code_row']['id']]);

                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }

            $this->respond([
                'status'  => 'success',
                'message' => '授权资料修改成功',
                'data'    => [
                    'owner'         => $changeOwner ? $owner : $license['owner_name'],
                    'contact_email' => $changeEmail ? $email : $license['contact_email'],
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('update error: ' . $e->getMessage());
            $this->respond(['status' => 'error', 'error' => 'server_error',
                'message' => '修改资料时服务异常，请稍后重试'], 500);
        }
    }
}
