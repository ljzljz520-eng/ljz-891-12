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

            // Lightweight idempotent migrations (keeps existing docker volumes working)
            $this->migrate($this->conn);
        } catch(PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            // Never print a raw HTML error to an API client
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(["status" => "error", "error" => "db_error", "message" => "数据库连接失败，请稍后重试"]);
            exit();
        }
        return $this->conn;
    }

    private function migrate(PDO $conn) {
        try {
            $db = $this->db_name;

            $columns = $conn->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = " . $conn->quote($db) . " AND TABLE_NAME = 'licenses'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (!in_array('contact_email', $columns)) {
                $conn->exec("ALTER TABLE licenses ADD COLUMN contact_email VARCHAR(120) DEFAULT NULL COMMENT '联系邮箱(可自助更绑)' AFTER upline");
            }

            $codeColumns = $conn->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = " . $conn->quote($db) . " AND TABLE_NAME = 'verification_codes'"
            )->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($codeColumns)) {
                if (!in_array('license_id', $codeColumns)) {
                    $conn->exec("ALTER TABLE verification_codes ADD COLUMN license_id INT DEFAULT NULL COMMENT '绑定的授权记录' AFTER identifier");
                }
                if (!in_array('used', $codeColumns)) {
                    $conn->exec("ALTER TABLE verification_codes ADD COLUMN used TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=未使用 1=已使用/已作废' AFTER code");
                }
                if (!in_array('attempts', $codeColumns)) {
                    $conn->exec("ALTER TABLE verification_codes ADD COLUMN attempts INT NOT NULL DEFAULT 0 COMMENT '错误尝试次数' AFTER used");
                }
            }
        } catch (\Throwable $e) {
            // Migration failures must never break the API response
            error_log("Migration error: " . $e->getMessage());
        }
    }
}
