<?php
session_start();

class Auth {
    private $db;
    private $table = 'faculty';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function register($email, $name, $password) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("INSERT INTO {$this->table} (email, name, password_hash, role, created_at) VALUES (?, ?, ?, 'faculty', NOW())");
        $stmt->bind_param("sss", $email, $name, $hash);
        return $stmt->execute();
    }
    
    public function login($email, $password) {
        $stmt = $this->db->prepare("SELECT id, name, email, role FROM {$this->table} WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            $stmt2 = $this->db->prepare("SELECT password_hash FROM {$this->table} WHERE id = ?");
            $stmt2->bind_param("i", $row['id']);
            $stmt2->execute();
            $pw = $stmt2->get_result()->fetch_assoc();
            
            if (password_verify($password, $pw['password_hash'])) {
                $_SESSION['faculty_id'] = $row['id'];
                $_SESSION['faculty_name'] = $row['name'];
                $_SESSION['faculty_email'] = $row['email'];
                $_SESSION['faculty_role'] = $row['role'];
                return true;
            }
        }
        return false;
    }
    
    public function logout() {
        unset($_SESSION['faculty_id']);
        session_destroy();
    }
    
    public function isLoggedIn() {
        return isset($_SESSION['faculty_id']);
    }
    
    public function getFacultyId() {
        return $_SESSION['faculty_id'] ?? null;
    }
    
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header("Location: " . APP_URL . "/index.php?page=login");
            exit;
        }
    }
}

$auth = new Auth();
?>
