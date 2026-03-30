<?php
/**
 * IndiaTweet - Twitter-like Microblogging Platform
 * Complete implementation in single PHP file
 * Version: 1.0.0
 * Author: RowBox
 */

// ============================================================================
// CONFIGURATION & CONSTANTS
// ============================================================================

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'indiatweet_db');
define('DB_USER', 'rowboxsiw');
define('DB_PASS', 'ABC13792588@MRK');
define('DB_CHARSET', 'utf8mb4');

// Application Configuration
define('APP_NAME', 'IndiaTweet');
define('APP_URL', 'http://localhost');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_DEBUG', true);

// Security Configuration
define('BCRYPT_COST', 12);
define('SESSION_TIMEOUT', 3600);
define('UPLOAD_MAX_SIZE', 50 * 1024 * 1024); // 50MB
define('IMAGE_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('RATE_LIMIT_ATTEMPTS', 5);
define('RATE_LIMIT_TIME', 900); // 15 minutes

// File Paths
define('STORAGE_PATH', dirname(__FILE__) . '/storage/');
define('UPLOAD_PATH', STORAGE_PATH . 'uploads/');
define('LOG_PATH', STORAGE_PATH . 'logs/');
define('CACHE_PATH', STORAGE_PATH . 'cache/');

// Create directories if they don't exist
if (!file_exists(STORAGE_PATH)) mkdir(STORAGE_PATH, 0755, true);
if (!file_exists(UPLOAD_PATH)) mkdir(UPLOAD_PATH, 0755, true);
if (!file_exists(LOG_PATH)) mkdir(LOG_PATH, 0755, true);
if (!file_exists(CACHE_PATH)) mkdir(CACHE_PATH, 0755, true);

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Start session
session_start();

// ============================================================================
// DATABASE CLASS
// ============================================================================

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->connection = new PDO($dsn, DB_USER, DB_PASS);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            $this->logError("Database Connection Failed: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    private function logError($message) {
        $log = "[" . date('Y-m-d H:i:s') . "] ERROR: " . $message . PHP_EOL;
        file_put_contents(LOG_PATH . 'database.log', $log, FILE_APPEND);
    }
}

// ============================================================================
// CORE MODEL CLASSES
// ============================================================================

class Model {
    protected $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    protected function sanitize($data) {
        if (is_array($data)) {
            return array_map([$this, 'sanitize'], $data);
        }
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
}

class User extends Model {
    public function create($data) {
        try {
            $sql = "INSERT INTO users (username, email, password_hash, full_name, phone, created_at) 
                    VALUES (:username, :email, :password_hash, :full_name, :phone, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':username' => $this->sanitize($data['username']),
                ':email' => filter_var($data['email'], FILTER_SANITIZE_EMAIL),
                ':password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]),
                ':full_name' => $this->sanitize($data['full_name']),
                ':phone' => $this->validatePhone($data['phone'] ?? '')
            ]);
            
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }
    
    public function findByEmail($email) {
        $sql = "SELECT * FROM users WHERE email = :email";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':email' => filter_var($email, FILTER_SANITIZE_EMAIL)]);
        return $stmt->fetch();
    }
    
    public function findByUsername($username) {
        $sql = "SELECT * FROM users WHERE username = :username";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':username' => $this->sanitize($username)]);
        return $stmt->fetch();
    }
    
    public function findById($id) {
        $sql = "SELECT id, username, email, full_name, bio, profile_image, cover_image, 
                       location, website, is_verified, created_at
                FROM users WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => (int)$id]);
        return $stmt->fetch();
    }
    
    public function updateProfile($userId, $data) {
        $allowedFields = ['full_name', 'bio', 'location', 'website'];
        $updates = [];
        $params = [':id' => $userId];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowedFields)) {
                $updates[] = "$key = :$key";
                $params[":$key"] = $this->sanitize($value);
            }
        }
        
        if (empty($updates)) return false;
        
        $sql = "UPDATE users SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    private function validatePhone($phone) {
        if (empty($phone)) return null;
        // Indian phone number validation
        if (preg_match('/^(\+91|91)?[6-9]\d{9}$/', $phone)) {
            return $phone;
        }
        return null;
    }
    
    private function logError($message) {
        $log = "[" . date('Y-m-d H:i:s') . "] User Model Error: " . $message . PHP_EOL;
        file_put_contents(LOG_PATH . 'app.log', $log, FILE_APPEND);
    }
}

class Tweet extends Model {
    public function create($userId, $content, $mediaType = 'none', $originalTweetId = null, $replyToTweetId = null) {
        try {
            $sql = "INSERT INTO tweets (user_id, content, media_type, original_tweet_id, reply_to_tweet_id, created_at)
                    VALUES (:user_id, :content, :media_type, :original_tweet_id, :reply_to_tweet_id, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':user_id' => (int)$userId,
                ':content' => $this->sanitize($content),
                ':media_type' => $mediaType,
                ':original_tweet_id' => $originalTweetId,
                ':reply_to_tweet_id' => $replyToTweetId
            ]);
            
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }
    
    public function getFeed($userId, $limit = 50, $offset = 0) {
        $sql = "SELECT t.*, u.username, u.profile_image, u.is_verified,
                       (SELECT COUNT(*) FROM likes WHERE tweet_id = t.id) as like_count,
                       (SELECT COUNT(*) FROM retweets WHERE tweet_id = t.id) as retweet_count,
                       (SELECT COUNT(*) FROM tweets WHERE reply_to_tweet_id = t.id) as reply_count
                FROM tweets t
                JOIN users u ON t.user_id = u.id
                LEFT JOIN follows f ON f.following_id = t.user_id
                WHERE f.follower_id = :user_id OR t.user_id = :user_id
                ORDER BY t.created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':limit' => (int)$limit,
            ':offset' => (int)$offset
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function getById($tweetId) {
        $sql = "SELECT t.*, u.username, u.profile_image, u.is_verified,
                       (SELECT COUNT(*) FROM likes WHERE tweet_id = t.id) as like_count,
                       (SELECT COUNT(*) FROM retweets WHERE tweet_id = t.id) as retweet_count
                FROM tweets t
                JOIN users u ON t.user_id = u.id
                WHERE t.id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $tweetId]);
        return $stmt->fetch();
    }
    
    public function getUserTweets($userId, $limit = 50, $offset = 0) {
        $sql = "SELECT t.*, u.username, u.profile_image, u.is_verified,
                       (SELECT COUNT(*) FROM likes WHERE tweet_id = t.id) as like_count,
                       (SELECT COUNT(*) FROM retweets WHERE tweet_id = t.id) as retweet_count
                FROM tweets t
                JOIN users u ON t.user_id = u.id
                WHERE t.user_id = :user_id
                ORDER BY t.created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':limit' => (int)$limit,
            ':offset' => (int)$offset
        ]);
        
        return $stmt->fetchAll();
    }
    
    public function like($userId, $tweetId) {
        try {
            $sql = "INSERT IGNORE INTO likes (user_id, tweet_id, created_at) 
                    VALUES (:user_id, :tweet_id, NOW())";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':user_id' => $userId,
                ':tweet_id' => $tweetId
            ]);
        } catch (PDOException $e) {
            return false;
        }
    }
    
    public function retweet($userId, $tweetId) {
        try {
            // Check if already retweeted
            $checkSql = "SELECT id FROM retweets WHERE user_id = :user_id AND tweet_id = :tweet_id";
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute([':user_id' => $userId, ':tweet_id' => $tweetId]);
            
            if ($checkStmt->fetch()) {
                // Remove retweet
                $deleteSql = "DELETE FROM retweets WHERE user_id = :user_id AND tweet_id = :tweet_id";
                $deleteStmt = $this->db->prepare($deleteSql);
                return $deleteStmt->execute([':user_id' => $userId, ':tweet_id' => $tweetId]);
            } else {
                // Add retweet
                $insertSql = "INSERT INTO retweets (user_id, tweet_id, created_at) 
                              VALUES (:user_id, :tweet_id, NOW())";
                $insertStmt = $this->db->prepare($insertSql);
                return $insertStmt->execute([':user_id' => $userId, ':tweet_id' => $tweetId]);
            }
        } catch (PDOException $e) {
            return false;
        }
    }
    
    private function logError($message) {
        $log = "[" . date('Y-m-d H:i:s') . "] Tweet Model Error: " . $message . PHP_EOL;
        file_put_contents(LOG_PATH . 'app.log', $log, FILE_APPEND);
    }
}

class Media extends Model {
    public function upload($userId, $tweetId, $file, $type) {
        try {
            // Validate file
            if (!$this->validateFile($file, $type)) {
                return false;
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '_' . md5($file['name']) . '.' . $extension;
            
            // Create directory structure
            $tweetDir = UPLOAD_PATH . 'tweets/' . $tweetId . '/';
            if (!file_exists($tweetDir)) {
                mkdir($tweetDir, 0755, true);
            }
            
            $filePath = $tweetDir . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $filePath)) {
                // Create thumbnail for images
                $thumbnailUrl = null;
                if ($type === 'image') {
                    $thumbnailUrl = $this->createThumbnail($filePath, $tweetDir);
                }
                
                // Save to database
                $sql = "INSERT INTO media (tweet_id, user_id, media_url, media_type, thumbnail_url, 
                                          size, mime_type, storage_path, created_at)
                        VALUES (:tweet_id, :user_id, :media_url, :media_type, :thumbnail_url,
                                :size, :mime_type, :storage_path, NOW())";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':tweet_id' => $tweetId,
                    ':user_id' => $userId,
                    ':media_url' => '/storage/uploads/tweets/' . $tweetId . '/' . $filename,
                    ':media_type' => $type,
                    ':thumbnail_url' => $thumbnailUrl,
                    ':size' => $file['size'],
                    ':mime_type' => $file['type'],
                    ':storage_path' => $filePath
                ]);
                
                return $this->db->lastInsertId();
            }
            
            return false;
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }
    
    private function validateFile($file, $type) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }
        
        // Check file size
        $maxSize = ($type === 'image') ? IMAGE_MAX_SIZE : UPLOAD_MAX_SIZE;
        if ($file['size'] > $maxSize) {
            return false;
        }
        
        // Check MIME type
        $allowedMimes = [
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'video' => ['video/mp4', 'video/webm'],
            'gif' => ['image/gif', 'video/mp4']
        ];
        
        if (!in_array($file['type'], $allowedMimes[$type])) {
            return false;
        }
        
        // Verify file content
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($actualMime, $allowedMimes[$type])) {
            return false;
        }
        
        return true;
    }
    
    private function createThumbnail($sourcePath, $destinationDir) {
        try {
            $info = getimagesize($sourcePath);
            $mime = $info['mime'];
            
            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($sourcePath);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($sourcePath);
                    break;
                case 'image/gif':
                    $image = imagecreatefromgif($sourcePath);
                    break;
                case 'image/webp':
                    $image = imagecreatefromwebp($sourcePath);
                    break;
                default:
                    return null;
            }
            
            $width = imagesx($image);
            $height = imagesy($image);
            
            $thumbWidth = 300;
            $thumbHeight = 200;
            
            $thumbnail = imagecreatetruecolor($thumbWidth, $thumbHeight);
            
            // Preserve transparency for PNG and GIF
            if ($mime === 'image/png' || $mime === 'image/gif') {
                imagecolortransparent($thumbnail, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
                imagealphablending($thumbnail, false);
                imagesavealpha($thumbnail, true);
            }
            
            imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, 
                             $thumbWidth, $thumbHeight, $width, $height);
            
            $thumbFilename = 'thumb_' . basename($sourcePath);
            $thumbPath = $destinationDir . $thumbFilename;
            
            switch ($mime) {
                case 'image/jpeg':
                    imagejpeg($thumbnail, $thumbPath, 85);
                    break;
                case 'image/png':
                    imagepng($thumbnail, $thumbPath, 8);
                    break;
                case 'image/gif':
                    imagegif($thumbnail, $thumbPath);
                    break;
                case 'image/webp':
                    imagewebp($thumbnail, $thumbPath, 85);
                    break;
            }
            
            imagedestroy($image);
            imagedestroy($thumbnail);
            
            return '/storage/uploads/tweets/' . basename($destinationDir) . '/' . $thumbFilename;
        } catch (Exception $e) {
            return null;
        }
    }
    
    private function logError($message) {
        $log = "[" . date('Y-m-d H:i:s') . "] Media Model Error: " . $message . PHP_EOL;
        file_put_contents(LOG_PATH . 'app.log', $log, FILE_APPEND);
    }
}

// ============================================================================
// AUTHENTICATION & SESSION MANAGEMENT
// ============================================================================

class Auth {
    private static $rateLimit = [];
    
    public static function attempt($email, $password) {
        // Rate limiting
        $ip = $_SERVER['REMOTE_ADDR'];
        $now = time();
        
        if (!isset(self::$rateLimit[$ip])) {
            self::$rateLimit[$ip] = ['attempts' => 0, 'lockout' => 0];
        }
        
        if (self::$rateLimit[$ip]['lockout'] > $now) {
            return ['success' => false, 'message' => 'Too many attempts. Try again later.'];
        }
        
        $userModel = new User();
        $user = $userModel->findByEmail($email);
        
        if (!$user) {
            self::recordFailedAttempt($ip);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        if (!password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt($ip);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Reset rate limit on successful login
        self::$rateLimit[$ip]['attempts'] = 0;
        self::$rateLimit[$ip]['lockout'] = 0;
        
        // Create session
        self::createSession($user);
        
        // Update last login
        self::updateLastLogin($user['id']);
        
        return ['success' => true, 'user' => $user];
    }
    
    public static function register($data) {
        $userModel = new User();
        
        // Check if email exists
        if ($userModel->findByEmail($data['email'])) {
            return ['success' => false, 'message' => 'Email already registered'];
        }
        
        // Check if username exists
        if ($userModel->findByUsername($data['username'])) {
            return ['success' => false, 'message' => 'Username already taken'];
        }
        
        // Validate password strength
        if (strlen($data['password']) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters'];
        }
        
        // Create user
        $userId = $userModel->create($data);
        
        if ($userId) {
            // Log user in
            $user = $userModel->findById($userId);
            self::createSession($user);
            return ['success' => true, 'user' => $user];
        }
        
        return ['success' => false, 'message' => 'Registration failed'];
    }
    
    public static function check() {
        return isset($_SESSION['user_id']) && isset($_SESSION['user_token']);
    }
    
    public static function user() {
        if (self::check()) {
            $userModel = new User();
            return $userModel->findById($_SESSION['user_id']);
        }
        return null;
    }
    
    public static function logout() {
        session_destroy();
        session_start();
    }
    
    private static function createSession($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
    }
    
    private static function updateLastLogin($userId) {
        try {
            $db = Database::getInstance()->getConnection();
            $sql = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $userId]);
        } catch (PDOException $e) {
            // Log error but don't break login
        }
    }
    
    private static function recordFailedAttempt($ip) {
        if (!isset(self::$rateLimit[$ip])) {
            self::$rateLimit[$ip] = ['attempts' => 0, 'lockout' => 0];
        }
        
        self::$rateLimit[$ip]['attempts']++;
        
        if (self::$rateLimit[$ip]['attempts'] >= RATE_LIMIT_ATTEMPTS) {
            self::$rateLimit[$ip]['lockout'] = time() + RATE_LIMIT_TIME;
            self::$rateLimit[$ip]['attempts'] = 0;
        }
    }
    
    public static function validateSession() {
        if (!self::check()) {
            return false;
        }
        
        // Check session timeout
        if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
            self::logout();
            return false;
        }
        
        $_SESSION['last_activity'] = time();
        return true;
    }
}

// ============================================================================
// CONTROLLER CLASSES
// ============================================================================

class BaseController {
    protected function jsonResponse($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
    
    protected function redirect($url) {
        header("Location: $url");
        exit;
    }
    
    protected function render($view, $data = []) {
        extract($data);
        include dirname(__FILE__) . "/views/$view.php";
    }
    
    protected function sanitizeInput($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}

class AuthController extends BaseController {
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email = $this->sanitizeInput($_POST['email']);
            $password = $_POST['password'];
            
            $result = Auth::attempt($email, $password);
            
            if ($result['success']) {
                $this->redirect('/');
            } else {
                $this->render('auth/login', ['error' => $result['message']]);
            }
        } else {
            $this->render('auth/login');
        }
    }
    
    public function register() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'username' => $this->sanitizeInput($_POST['username']),
                'email' => filter_var($_POST['email'], FILTER_SANITIZE_EMAIL),
                'password' => $_POST['password'],
                'full_name' => $this->sanitizeInput($_POST['full_name']),
                'phone' => $this->sanitizeInput($_POST['phone'] ?? '')
            ];
            
            $result = Auth::register($data);
            
            if ($result['success']) {
                $this->redirect('/');
            } else {
                $this->render('auth/register', ['error' => $result['message']]);
            }
        } else {
            $this->render('auth/register');
        }
    }
    
    public function logout() {
        Auth::logout();
        $this->redirect('/login');
    }
}

class TweetController extends BaseController {
    public function index() {
        if (!Auth::validateSession()) {
            $this->redirect('/login');
        }
        
        $tweetModel = new Tweet();
        $user = Auth::user();
        
        $tweets = $tweetModel->getFeed($user['id']);
        
        $this->render('tweets/feed', [
            'tweets' => $tweets,
            'user' => $user
        ]);
    }
    
    public function create() {
        if (!Auth::validateSession()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user = Auth::user();
            $content = $this->sanitizeInput($_POST['content'] ?? '');
            
            if (empty($content) || strlen($content) > 280) {
                $this->jsonResponse(['error' => 'Invalid tweet content'], 400);
            }
            
            $tweetModel = new Tweet();
            $mediaModel = new Media();
            
            // Handle media upload
            $mediaType = 'none';
            if (isset($_FILES['media']) && $_FILES['media']['error'] === UPLOAD_ERR_OK) {
                $fileType = $_FILES['media']['type'];
                
                if (strpos($fileType, 'image/') === 0) {
                    $mediaType = 'image';
                } elseif (strpos($fileType, 'video/') === 0) {
                    $mediaType = 'video';
                }
            }
            
            $tweetId = $tweetModel->create($user['id'], $content, $mediaType);
            
            if ($tweetId && $mediaType !== 'none') {
                $mediaModel->upload($user['id'], $tweetId, $_FILES['media'], $mediaType);
            }
            
            $this->jsonResponse([
                'success' => true,
                'tweet_id' => $tweetId,
                'message' => 'Tweet posted successfully'
            ]);
        }
    }
    
    public function like($tweetId) {
        if (!Auth::validateSession()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }
        
        $user = Auth::user();
        $tweetModel = new Tweet();
        
        if ($tweetModel->like($user['id'], $tweetId)) {
            $this->jsonResponse(['success' => true]);
        } else {
            $this->jsonResponse(['error' => 'Failed to like tweet'], 500);
        }
    }
    
    public function retweet($tweetId) {
        if (!Auth::validateSession()) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }
        
        $user = Auth::user();
        $tweetModel = new Tweet();
        
        if ($tweetModel->retweet($user['id'], $tweetId)) {
            $this->jsonResponse(['success' => true]);
        } else {
            $this->jsonResponse(['error' => 'Failed to retweet'], 500);
        }
    }
    
    public function view($tweetId) {
        $tweetModel = new Tweet();
        $tweet = $tweetModel->getById($tweetId);
        
        if (!$tweet) {
            $this->render('error/404');
            return;
        }
        
        $this->render('tweets/view', [
            'tweet' => $tweet,
            'user' => Auth::user()
        ]);
    }
}

class UserController extends BaseController {
    public function profile($username = null) {
        $userModel = new User();
        $tweetModel = new Tweet();
        
        if ($username === null) {
            if (!Auth::validateSession()) {
                $this->redirect('/login');
            }
            $profileUser = Auth::user();
        } else {
            $profileUser = $userModel->findByUsername($username);
            if (!$profileUser) {
                $this->render('error/404');
                return;
            }
        }
        
        $tweets = $tweetModel->getUserTweets($profileUser['id']);
        
        $this->render('users/profile', [
            'profileUser' => $profileUser,
            'tweets' => $tweets,
            'currentUser' => Auth::user()
        ]);
    }
    
    public function updateProfile() {
        if (!Auth::validateSession()) {
            $this->redirect('/login');
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user = Auth::user();
            $userModel = new User();
            
            $data = [
                'full_name' => $this->sanitizeInput($_POST['full_name'] ?? ''),
                'bio' => $this->sanitizeInput($_POST['bio'] ?? ''),
                'location' => $this->sanitizeInput($_POST['location'] ?? ''),
                'website' => filter_var($_POST['website'] ?? '', FILTER_SANITIZE_URL)
            ];
            
            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $mediaModel = new Media();
                $imagePath = $mediaModel->uploadProfileImage($user['id'], $_FILES['profile_image']);
                if ($imagePath) {
                    $data['profile_image'] = $imagePath;
                }
            }
            
            if ($userModel->updateProfile($user['id'], $data)) {
                $this->redirect('/profile');
            } else {
                $this->render('users/edit', [
                    'user' => $user,
                    'error' => 'Failed to update profile'
                ]);
            }
        } else {
            $this->render('users/edit', ['user' => Auth::user()]);
        }
    }
}

// ============================================================================
// VIEW TEMPLATES
// ============================================================================

// Create views directory
$viewsDir = dirname(__FILE__) . '/views';
if (!file_exists($viewsDir)) {
    mkdir($viewsDir, 0755, true);
    mkdir($viewsDir . '/auth', 0755, true);
    mkdir($viewsDir . '/tweets', 0755, true);
    mkdir($viewsDir . '/users', 0755, true);
    mkdir($viewsDir . '/error', 0755, true);
    mkdir($viewsDir . '/admin', 0755, true);
}

// Layout Template
$layout = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title ?? 'IndiaTweet'; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
               background: #15202b; color: #fff; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; 
                  padding: 15px 0; border-bottom: 1px solid #38444d; }
        .logo { font-size: 24px; font-weight: bold; color: #1da1f2; text-decoration: none; }
        .nav a { color: #fff; text-decoration: none; margin-left: 20px; }
        .tweet-box { background: #192734; border-radius: 10px; padding: 15px; margin: 20px 0; }
        .tweet-textarea { width: 100%; background: transparent; border: none; 
                         color: #fff; font-size: 16px; resize: none; min-height: 100px; }
        .tweet-actions { display: flex; justify-content: space-between; margin-top: 10px; }
        .btn { background: #1da1f2; color: white; border: none; padding: 10px 20px; 
               border-radius: 20px; cursor: pointer; font-weight: bold; }
        .btn:hover { background: #1a91da; }
        .tweet { background: #192734; border-radius: 10px; padding: 15px; margin: 15px 0; }
        .tweet-header { display: flex; align-items: center; margin-bottom: 10px; }
        .tweet-user { font-weight: bold; margin-left: 10px; }
        .tweet-verified { color: #1da1f2; margin-left: 5px; }
        .tweet-content { margin: 10px 0; line-height: 1.5; }
        .tweet-actions { display: flex; gap: 20px; margin-top: 15px; }
        .tweet-action { color: #8899a6; cursor: pointer; }
        .tweet-action:hover { color: #1da1f2; }
        .form-group { margin-bottom: 15px; }
        .form-control { width: 100%; padding: 10px; border-radius: 5px; 
                       border: 1px solid #38444d; background: #192734; color: #fff; }
        .error { color: #e0245e; margin-top: 5px; }
        .success { color: #17bf63; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="/" class="logo">IndiaTweet</a>
            <div class="nav">
                <?php if (Auth::check()): ?>
                    <a href="/">Home</a>
                    <a href="/profile">Profile</a>
                    <a href="/logout">Logout</a>
                <?php else: ?>
                    <a href="/login">Login</a>
                    <a href="/register">Register</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php echo $content ?? ''; ?>
    </div>
    
    <script>
        // Auto-expand textarea
        document.querySelectorAll('.tweet-textarea').forEach(textarea => {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = (this.scrollHeight) + 'px';
            });
        });
        
        // Handle tweet actions
        function likeTweet(tweetId) {
            fetch(`/api/tweet/${tweetId}/like`, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
        }
        
        function retweet(tweetId) {
            fetch(`/api/tweet/${tweetId}/retweet`, { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
        }
    </script>
</body>
</html>
HTML;

file_put_contents($viewsDir . '/layout.php', $layout);

// Login View
$loginView = <<<'HTML'
<?php ob_start(); ?>
<h2>Login to IndiaTweet</h2>
<form method="POST" action="/login" style="max-width: 400px; margin: 30px auto;">
    <div class="form-group">
        <input type="email" name="email" class="form-control" placeholder="Email" required>
    </div>
    <div class="form-group">
        <input type="password" name="password" class="form-control" placeholder="Password" required>
    </div>
    <button type="submit" class="btn" style="width: 100%;">Login</button>
    <div style="text-align: center; margin-top: 15px;">
        <a href="/register" style="color: #1da1f2;">Don't have an account? Register</a>
    </div>
</form>
<?php $content = ob_get_clean(); ?>
<?php include 'layout.php'; ?>
HTML;

file_put_contents($viewsDir . '/auth/login.php', $loginView);

// Register View
$registerView = <<<'HTML'
<?php ob_start(); ?>
<h2>Join IndiaTweet</h2>
<form method="POST" action="/register" style="max-width: 400px; margin: 30px auto;">
    <div class="form-group">
        <input type="text" name="username" class="form-control" placeholder="Username" required>
    </div>
    <div class="form-group">
        <input type="email" name="email" class="form-control" placeholder="Email" required>
    </div>
    <div class="form-group">
        <input type="password" name="password" class="form-control" placeholder="Password" required>
    </div>
    <div class="form-group">
        <input type="text" name="full_name" class="form-control" placeholder="Full Name" required>
    </div>
    <div class="form-group">
        <input type="text" name="phone" class="form-control" placeholder="Phone (optional)">
    </div>
    <button type="submit" class="btn" style="width: 100%;">Register</button>
    <div style="text-align: center; margin-top: 15px;">
        <a href="/login" style="color: #1da1f2;">Already have an account? Login</a>
    </div>
</form>
<?php $content = ob_get_clean(); ?>
<?php include 'layout.php'; ?>
HTML;

file_put_contents($viewsDir . '/auth/register.php', $registerView);

// Feed View
$feedView = <<<'HTML'
<?php ob_start(); ?>
<div class="tweet-box">
    <form id="tweetForm" enctype="multipart/form-data">
        <textarea name="content" class="tweet-textarea" placeholder="What's happening?" maxlength="280"></textarea>
        <div class="tweet-actions">
            <input type="file" name="media" id="media" accept="image/*,video/*" style="display: none;">
            <label for="media" style="cursor: pointer; color: #1da1f2;">📷 Media</label>
            <span id="charCount" style="color: #8899a6;">280</span>
            <button type="submit" class="btn">Tweet</button>
        </div>
    </form>
</div>

<div id="tweets">
    <?php foreach ($tweets as $tweet): ?>
        <div class="tweet">
            <div class="tweet-header">
                <img src="<?php echo $tweet['profile_image'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($tweet['username']); ?>" 
                     alt="<?php echo $tweet['username']; ?>" 
                     style="width: 40px; height: 40px; border-radius: 50%;">
                <div class="tweet-user">
                    <?php echo htmlspecialchars($tweet['full_name'] ?? $tweet['username']); ?>
                    <?php if ($tweet['is_verified']): ?>
                        <span class="tweet-verified">✓</span>
                    <?php endif; ?>
                    <div style="color: #8899a6; font-size: 14px;">
                        @<?php echo $tweet['username']; ?> · 
                        <?php echo date('M j', strtotime($tweet['created_at'])); ?>
                    </div>
                </div>
            </div>
            <div class="tweet-content">
                <?php echo nl2br(htmlspecialchars($tweet['content'])); ?>
            </div>
            <div class="tweet-actions">
                <span class="tweet-action" onclick="likeTweet(<?php echo $tweet['id']; ?>)">
                    ♥ <?php echo $tweet['like_count'] ?? 0; ?>
                </span>
                <span class="tweet-action" onclick="retweet(<?php echo $tweet['id']; ?>)">
                    🔄 <?php echo $tweet['retweet_count'] ?? 0; ?>
                </span>
                <span class="tweet-action">
                    💬 <?php echo $tweet['reply_count'] ?? 0; ?>
                </span>
                <span class="tweet-action">
                    👁️ <?php echo $tweet['view_count'] ?? 0; ?>
                </span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
    // Character counter
    const textarea = document.querySelector('.tweet-textarea');
    const charCount = document.getElementById('charCount');
    
    textarea.addEventListener('input', function() {
        const remaining = 280 - this.value.length;
        charCount.textContent = remaining;
        charCount.style.color = remaining < 0 ? '#e0245e' : '#8899a6';
    });
    
    // Tweet form submission
    document.getElementById('tweetForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch('/api/tweet/create', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert(data.error || 'Failed to post tweet');
            }
        });
    });
</script>
<?php $content = ob_get_clean(); ?>
<?php $title = 'Home - IndiaTweet'; ?>
<?php include 'layout.php'; ?>
HTML;

file_put_contents($viewsDir . '/tweets/feed.php', $feedView);

// Profile View
$profileView = <<<'HTML'
<?php ob_start(); ?>
<div style="position: relative; margin-bottom: 20px;">
    <div style="height: 200px; background: #1da1f2; border-radius: 10px;"></div>
    <div style="position: absolute; bottom: -50px; left: 20px;">
        <img src="<?php echo $profileUser['profile_image'] ?: 'https://ui-avatars.com/api/?name=' . urlencode($profileUser['username']); ?>" 
             alt="<?php echo $profileUser['username']; ?>" 
             style="width: 100px; height: 100px; border-radius: 50%; border: 4px solid #15202b;">
    </div>
    <?php if ($currentUser && $currentUser['id'] == $profileUser['id']): ?>
        <div style="position: absolute; bottom: 20px; right: 20px;">
            <a href="/profile/edit" class="btn">Edit Profile</a>
        </div>
    <?php endif; ?>
</div>

<div style="margin-top: 60px;">
    <h2><?php echo htmlspecialchars($profileUser['full_name'] ?? $profileUser['username']); ?></h2>
    <div style="color: #8899a6; margin-bottom: 10px;">
        @<?php echo $profileUser['username']; ?>
        <?php if ($profileUser['is_verified']): ?>
            <span style="color: #1da1f2;">✓ Verified</span>
        <?php endif; ?>
    </div>
    
    <?php if ($profileUser['bio']): ?>
        <p style="margin: 15px 0;"><?php echo nl2br(htmlspecialchars($profileUser['bio'])); ?></p>
    <?php endif; ?>
    
    <div style="color: #8899a6; font-size: 14px; margin-bottom: 20px;">
        <?php if ($profileUser['location']): ?>
            📍 <?php echo htmlspecialchars($profileUser['location']); ?>
        <?php endif; ?>
        
        <?php if ($profileUser['website']): ?>
            🔗 <a href="<?php echo htmlspecialchars($profileUser['website']); ?>" 
                 style="color: #1da1f2;"><?php echo htmlspecialchars($profileUser['website']); ?></a>
        <?php endif; ?>
        
        <div style="margin-top: 5px;">
            📅 Joined <?php echo date('F Y', strtotime($profileUser['created_at'])); ?>
        </div>
    </div>
</div>

<div style="border-top: 1px solid #38444d; margin-top: 20px; padding-top: 20px;">
    <h3>Tweets</h3>
    <?php foreach ($tweets as $tweet): ?>
        <div class="tweet" style="margin: 15px 0;">
            <div class="tweet-content">
                <?php echo nl2br(htmlspecialchars($tweet['content'])); ?>
            </div>
            <div style="color: #8899a6; font-size: 14px; margin-top: 10px;">
                <?php echo date('M j, Y · g:i A', strtotime($tweet['created_at'])); ?>
                ·
                ♥ <?php echo $tweet['like_count'] ?? 0; ?>
                ·
                🔄 <?php echo $tweet['retweet_count'] ?? 0; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php $content = ob_get_clean(); ?>
<?php $title = '@' . $profileUser['username'] . ' - IndiaTweet'; ?>
<?php include 'layout.php'; ?>
HTML;

file_put_contents($viewsDir . '/users/profile.php', $profileView);

// Error 404 View
$error404View = <<<'HTML'
<?php ob_start(); ?>
<div style="text-align: center; padding: 50px 20px;">
    <h1 style="font-size: 100px; color: #1da1f2;">404</h1>
    <h2>Page Not Found</h2>
    <p style="color: #8899a6; margin: 20px 0;">The page you're looking for doesn't exist.</p>
    <a href="/" class="btn">Go Home</a>
</div>
<?php $content = ob_get_clean(); ?>
<?php include 'layout.php'; ?>
HTML;

file_put_contents($viewsDir . '/error/404.php', $error404View);

// ============================================================================
// ROUTING SYSTEM
// ============================================================================

class Router {
    private $routes = [];
    
    public function add($method, $path, $handler) {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler
        ];
    }
    
    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = rtrim($path, '/') ?: '/';
        
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->matchPath($route['path'], $path)) {
                return $this->callHandler($route['handler'], $this->extractParams($route['path'], $path));
            }
        }
        
        // 404 Not Found
        http_response_code(404);
        include dirname(__FILE__) . '/views/error/404.php';
        exit;
    }
    
    private function matchPath($pattern, $path) {
        $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $pattern);
        return preg_match("#^$pattern$#", $path);
    }
    
    private function extractParams($pattern, $path) {
        $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $pattern);
        preg_match("#^$pattern$#", $path, $matches);
        array_shift($matches);
        return $matches;
    }
    
    private function callHandler($handler, $params) {
        if (is_callable($handler)) {
            return call_user_func_array($handler, $params);
        }
        
        if (is_string($handler) && strpos($handler, '@') !== false) {
            list($controller, $method) = explode('@', $handler);
            $controllerClass = $controller . 'Controller';
            
            if (class_exists($controllerClass)) {
                $controllerInstance = new $controllerClass();
                if (method_exists($controllerInstance, $method)) {
                    return call_user_func_array([$controllerInstance, $method], $params);
                }
            }
        }
        
        throw new Exception("Handler not found");
    }
}

// ============================================================================
// DATABASE SETUP SCRIPT
// ============================================================================

function setupDatabase() {
    try {
        $db = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database if not exists
        $db->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("USE " . DB_NAME);
        
        // Users table
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255),
            google_id VARCHAR(255) UNIQUE,
            phone VARCHAR(15),
            full_name VARCHAR(100),
            bio TEXT,
            profile_image VARCHAR(255),
            cover_image VARCHAR(255),
            location VARCHAR(100),
            website VARCHAR(255),
            date_of_birth DATE,
            is_verified BOOLEAN DEFAULT FALSE,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            INDEX idx_email (email),
            INDEX idx_username (username),
            INDEX idx_google_id (google_id)
        )");
        
        // Tweets table
        $db->exec("CREATE TABLE IF NOT EXISTS tweets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            content TEXT,
            original_tweet_id INT NULL,
            reply_to_tweet_id INT NULL,
            media_type ENUM('none', 'image', 'video', 'gif', 'poll') DEFAULT 'none',
            is_sensitive BOOLEAN DEFAULT FALSE,
            view_count INT DEFAULT 0,
            like_count INT DEFAULT 0,
            retweet_count INT DEFAULT 0,
            reply_count INT DEFAULT 0,
            language VARCHAR(10) DEFAULT 'en',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (original_tweet_id) REFERENCES tweets(id) ON DELETE SET NULL,
            FOREIGN KEY (reply_to_tweet_id) REFERENCES tweets(id) ON DELETE SET NULL,
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at),
            FULLTEXT idx_content (content)
        )");
        
        // Media table
        $db->exec("CREATE TABLE IF NOT EXISTS media (
            id INT PRIMARY KEY AUTO_INCREMENT,
            tweet_id INT NOT NULL,
            user_id INT NOT NULL,
            media_url VARCHAR(500) NOT NULL,
            media_type ENUM('image', 'video', 'gif') NOT NULL,
            thumbnail_url VARCHAR(500),
            duration INT,
            size INT,
            mime_type VARCHAR(100),
            storage_path VARCHAR(500),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tweet_id) REFERENCES tweets(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_tweet_id (tweet_id),
            INDEX idx_user_id (user_id)
        )");
        
        // Likes table
        $db->exec("CREATE TABLE IF NOT EXISTS likes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            tweet_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_like (user_id, tweet_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (tweet_id) REFERENCES tweets(id) ON DELETE CASCADE
        )");
        
        // Retweets table
        $db->exec("CREATE TABLE IF NOT EXISTS retweets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            tweet_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_retweet (user_id, tweet_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (tweet_id) REFERENCES tweets(id) ON DELETE CASCADE
        )");
        
        // Follows table
        $db->exec("CREATE TABLE IF NOT EXISTS follows (
            id INT PRIMARY KEY AUTO_INCREMENT,
            follower_id INT NOT NULL,
            following_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_follow (follower_id, following_id),
            FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        // Sessions table
        $db->exec("CREATE TABLE IF NOT EXISTS user_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            session_token VARCHAR(255) UNIQUE NOT NULL,
            device_info TEXT,
            ip_address VARCHAR(45),
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_session_token (session_token),
            INDEX idx_user_id (user_id)
        )");
        
        // Admin users table
        $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('super_admin', 'moderator', 'analyst') DEFAULT 'moderator',
            permissions JSON,
            is_active BOOLEAN DEFAULT TRUE,
            last_login TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Create default admin user (password: Admin123!)
        $adminPassword = password_hash('Admin123!', PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
        $db->exec("INSERT IGNORE INTO admin_users (username, email, password_hash, role) 
                   VALUES ('admin', 'rowboxsiw@gmail.com', '$adminPassword', 'super_admin')");
        
        return true;
        
    } catch (PDOException $e) {
        error_log("Database setup failed: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// INITIALIZE APPLICATION
// ============================================================================

// Check if we need to run setup
if (isset($_GET['setup']) && $_GET['setup'] === 'install') {
    if (setupDatabase()) {
        echo "<h1>Database Setup Complete!</h1>";
        echo "<p>IndiaTweet database has been created successfully.</p>";
        echo "<a href='/'>Go to Homepage</a>";
    } else {
        echo "<h1>Database Setup Failed!</h1>";
        echo "<p>Please check your database credentials and try again.</p>";
    }
    exit;
}

// Initialize router
$router = new Router();

// Define routes
$router->add('GET', '/', 'Tweet@index');
$router->add('GET', '/login', 'Auth@login');
$router->add('POST', '/login', 'Auth@login');
$router->add('GET', '/register', 'Auth@register');
$router->add('POST', '/register', 'Auth@register');
$router->add('GET', '/logout', 'Auth@logout');
$router->add('GET', '/profile', 'User@profile');
$router->add('GET', '/profile/{username}', 'User@profile');
$router->add('GET', '/tweet/{id}', 'Tweet@view');
$router->add('GET', '/profile/edit', 'User@updateProfile');
$router->add('POST', '/profile/update', 'User@updateProfile');

// API Routes
$router->add('POST', '/api/tweet/create', 'Tweet@create');
$router->add('POST', '/api/tweet/{id}/like', 'Tweet@like');
$router->add('POST', '/api/tweet/{id}/retweet', 'Tweet@retweet');

// Dispatch request
try {
    $router->dispatch();
} catch (Exception $e) {
    http_response_code(500);
    echo "Internal Server Error";
    if (APP_DEBUG) {
        echo ": " . $e->getMessage();
    }
}
?>