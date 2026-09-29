<?php
namespace Controllers;

use Config\Database;
use PDO;

class LicenseController {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    // Public Query
    public function query() {
        if (!isset($_GET['qq']) || !isset($_GET['owner'])) {
            http_response_code(400);
            echo json_encode(["message" => "Missing parameters"]);
            return;
        }

        $qq = $_GET['qq'];
        $owner = $_GET['owner'];

        $query = "SELECT * FROM licenses WHERE qq = :qq AND owner_name = :owner LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(":qq", $qq);
        $stmt->bindParam(":owner", $owner);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            // Check if expired logic? The prompt says just show info.
            // But prompt also lists reasons for failure: "1.授权开通不足60分钟内" (implies < 60 mins from creation?) - this is weird, maybe it means 'just created'? or 'not synced'?
            // Usually "Authorization not found" reasons are generic boilerplate.
            // Let's just return the data.
            
            http_response_code(200);
            echo json_encode([
                "status" => "success",
                "data" => [
                    "qq" => $row['qq'],
                    "owner" => $row['owner_name'],
                    "product" => $row['product_name'],
                    "upline" => $row['upline'],
                    "expiration" => $row['expiration_date'],
                    "created_at" => $row['created_at']
                ]
            ]);
        } else {
            // Failure with specific message
            http_response_code(404);
            echo json_encode([
                "status" => "error",
                "message" => "暂未查询到您的授权信息 请查证后再次查询！",
                "reasons" => [
                    "1.授权开通不足60分钟内",
                    "2.未购买正版授权，可能是盗版程序授权",
                    "3.恭喜你，被圈钱了！"
                ]
            ]);
        }
    }

    // Admin: List All
    public function listAll() {
        $query = "SELECT * FROM licenses ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    }

    // Admin: Create
    public function create() {
        $data = json_decode(file_get_contents("php://input"));
        // Need: qq, owner_name, product_name, upline, expiration_date
        $query = "INSERT INTO licenses (qq, owner_name, product_name, upline, expiration_date) VALUES (:qq, :owner, :product, :upline, :exp)";
        $stmt = $this->db->prepare($query);
        
        $params = [
            ":qq" => $data->qq,
            ":owner" => $data->owner_name,
            ":product" => $data->product_name,
            ":upline" => $data->upline,
            ":exp" => $data->expiration_date
        ];
        
        if($stmt->execute($params)) {
             echo json_encode(["message" => "Created successfully"]);
        } else {
             http_response_code(500);
             echo json_encode(["message" => "Create failed"]);
        }
    }
    
    // Admin: Delete
    public function delete() {
         $data = json_decode(file_get_contents("php://input"));
         if(!isset($data->id)) { return; }
         $query = "DELETE FROM licenses WHERE id = :id";
         $stmt = $this->db->prepare($query);
         $stmt->bindParam(":id", $data->id);
         $stmt->execute();
         echo json_encode(["message" => "Deleted"]);
    }

    // Update Flow: Step 1 - Verify identity (QQ + product) & Send Code
    public function sendVerificationCode() {
        try {
            $data = json_decode(file_get_contents("php://input"));
            $qq = trim($data->qq ?? '');
            $product = trim($data->product_name ?? '');

            if ($qq === '' || $product === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "请填写授权QQ和所属产品"]);
                return;
            }

            // 1. Verify the license exists (QQ + product must match)
            $stmt = $this->db->prepare(
                "SELECT id, owner_name, contact_email FROM licenses WHERE qq = :qq AND product_name = :product LIMIT 1"
            );
            $stmt->execute([':qq' => $qq, ':product' => $product]);
            $license = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$license) {
                http_response_code(404);
                echo json_encode([
                    "status" => "error",
                    "message" => "未找到对应的授权信息，请确认授权QQ和所属产品是否正确"
                ]);
                return;
            }

            // The bound email is the QQ mailbox; contact_email is only a forwarding/contact address.
            $email = $qq . "@qq.com";

            // 2. Rate limiting: one code per 60 seconds per identifier
            $rateStmt = $this->db->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS ago FROM verification_codes WHERE identifier = :email"
            );
            $rateStmt->execute([':email' => $email]);
            $ago = $rateStmt->fetchColumn();
            if ($ago !== false && $ago !== null && (int)$ago < 60) {
                $wait = 60 - (int)$ago;
                http_response_code(429);
                echo json_encode([
                    "status" => "error",
                    "message" => "验证码发送过于频繁，请 {$wait} 秒后再试",
                    "retry_after" => $wait
                ]);
                return;
            }

            // 3. Generate & store code (valid for 10 minutes)
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $ins = $this->db->prepare(
                "INSERT INTO verification_codes (type, identifier, code, expires_at) VALUES ('update_license', :email, :code, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
            );
            $ins->execute([':email' => $email, ':code' => $code]);

            // 4. Send email via PHPMailer (never white-screen on failure)
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            try {
                // Server settings
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = 'error_log';
                $mail->isSMTP();
                $mail->Host       = 'smtp.163.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'yuwangifeng@163.com';
                $mail->Password   = 'LRZMA358wePVGa8F';
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;
                $mail->CharSet    = 'UTF-8';

                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->setFrom('yuwangifeng@163.com');
                $mail->addAddress($email);
                $mail->Hostname = 'localhost';

                $mail->isHTML(true);
                $mail->Subject = '【授权系统】验证码';
                $mail->Body    = "您正在申请修改授权资料，验证码为 <b>$code</b>，请在10分钟内完成验证。<br>如非本人操作请忽略，您的授权信息不会受到影响。";

                $mail->send();
                echo json_encode([
                    "status" => "success",
                    "message" => "验证码已发送至绑定邮箱 {$email}，请在10分钟内完成验证",
                    "email" => $email
                ]);
            } catch (\Exception $e) {
                // SMTP failure: return JSON error instead of a white screen.
                // Keep a mock code for demo/testing environments (flagged clearly).
                error_log("SMTP Error: " . $mail->ErrorInfo);
                http_response_code(200);
                echo json_encode([
                    "status" => "error",
                    "message" => "邮件发送失败，请稍后重试或联系客服",
                    "mock_mode" => true,
                    "mock_code" => $code,
                    "debug_error" => $mail->ErrorInfo
                ]);
            }
        } catch (\Throwable $e) {
            error_log("sendVerificationCode error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "服务异常，请稍后重试"]);
        }
    }

    // Update Flow: Step 2 - Verify code & update license owner / contact email
    public function update() {
        try {
            $data = json_decode(file_get_contents("php://input"));
            $qq = trim($data->qq ?? '');
            $product = trim($data->product_name ?? '');
            $code = trim($data->code ?? '');
            $newOwner = trim($data->new_owner ?? '');
            $newEmail = trim($data->new_email ?? '');

            if ($qq === '' || $product === '' || $code === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "请填写授权QQ、所属产品和邮箱验证码"]);
                return;
            }

            // 1. Verify the license exists
            $stmt = $this->db->prepare(
                "SELECT id FROM licenses WHERE qq = :qq AND product_name = :product LIMIT 1"
            );
            $stmt->execute([':qq' => $qq, ':product' => $product]);
            $license = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$license) {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "未找到对应的授权信息，请确认授权QQ和所属产品"]);
                return;
            }

            // 2. Validate the verification code (latest one for this identifier)
            $email = $qq . "@qq.com";
            $codeStmt = $this->db->prepare(
                "SELECT * FROM verification_codes WHERE identifier = :email ORDER BY id DESC LIMIT 1"
            );
            $codeStmt->execute([':email' => $email]);
            $record = $codeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$record) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "请先获取邮箱验证码"]);
                return;
            }
            if (!empty($record['used_at'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "验证码已使用，请重新获取"]);
                return;
            }
            if (strtotime($record['expires_at']) < time()) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "验证码已过期，请重新获取"]);
                return;
            }
            if ($record['code'] !== $code) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "验证码错误，请重新输入"]);
                return;
            }

            // 3. Validate new values
            if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "联系邮箱格式不正确"]);
                return;
            }
            if ($newOwner === '' && $newEmail === '') {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "请填写需要修改的内容（授权主人或联系邮箱）"]);
                return;
            }

            // 4. Mark code as used (one-time use)
            $useStmt = $this->db->prepare("UPDATE verification_codes SET used_at = NOW() WHERE id = :id");
            $useStmt->execute([':id' => $record['id']]);

            // 5. Apply updates
            $fields = [];
            $params = [':qq' => $qq, ':product' => $product];
            if ($newOwner !== '') {
                $fields[] = "owner_name = :owner";
                $params[':owner'] = $newOwner;
            }
            if ($newEmail !== '') {
                $fields[] = "contact_email = :cemail";
                $params[':cemail'] = $newEmail;
            }

            $upd = $this->db->prepare(
                "UPDATE licenses SET " . implode(', ', $fields) . " WHERE qq = :qq AND product_name = :product"
            );
            $upd->execute($params);

            // 6. Return updated license info
            $infoStmt = $this->db->prepare(
                "SELECT qq, owner_name, product_name, upline, contact_email, expiration_date FROM licenses WHERE qq = :qq AND product_name = :product LIMIT 1"
            );
            $infoStmt->execute([':qq' => $qq, ':product' => $product]);
            $info = $infoStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "success",
                "message" => "授权资料修改成功",
                "data" => $info
            ]);
        } catch (\Throwable $e) {
            error_log("update error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "服务异常，请稍后重试"]);
        }
    }
}
