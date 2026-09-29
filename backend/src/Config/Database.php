<?php
namespace Config;

use PDO;
use PDOException;

class Database {
    private $host = 'db';
    private $db_name = 'auth_system';
    private $username = 'root';
    private $password = 'root';
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $this->conn = new PDO($dsn, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->exec("set names utf8mb4");
            $this->migrate();
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        return $this->conn;
    }

    /**
     * Idempotent schema migration for existing databases.
     * Fresh installs already have these columns via init.sql.
     */
    private function migrate() {
        try {
            // licenses.contact_email
            $col = $this->conn->query("SHOW COLUMNS FROM licenses LIKE 'contact_email'")->fetch();
            if (!$col) {
                $this->conn->exec("ALTER TABLE licenses ADD COLUMN contact_email VARCHAR(100) DEFAULT NULL COMMENT '联系邮箱（留空则使用绑定QQ邮箱）' AFTER upline");
            }
            // verification_codes.used_at
            $col = $this->conn->query("SHOW COLUMNS FROM verification_codes LIKE 'used_at'")->fetch();
            if (!$col) {
                $this->conn->exec("ALTER TABLE verification_codes ADD COLUMN used_at DATETIME DEFAULT NULL COMMENT '使用时间，防止验证码重复使用' AFTER expires_at");
            }
        } catch (PDOException $e) {
            // Tables may not exist yet on first boot; init.sql will create them.
            error_log("Migration skipped: " . $e->getMessage());
        }
    }
}
