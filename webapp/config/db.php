<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'egrading');
define('DB_PORT', 3306);

// App Config
define('APP_NAME', 'E-Grading System');
define('APP_URL', 'http://localhost/thesisEgrading/webapp');
define('APP_ENV', 'development');

class Database {
    private $conn;
    private static $instance;
    
    private function __construct() {
        try {
            $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
            if ($this->conn->connect_error) throw new Exception($this->conn->connect_error);
            $this->conn->set_charset("utf8mb4");
        } catch (Exception $e) {
            die("Database Connection Error: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function query($sql) {
        return $this->conn->query($sql);
    }
    
    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }
    
    public function escape($str) {
        return $this->conn->real_escape_string($str);
    }
}
?>
