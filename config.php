<?php
// Database configuration
$host = 'localhost';
$dbname = 'file_sharing';
$dbUser = 'root';  // Change this to your database username
$dbPass = '';      // Change this to your database password

// PDO options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// DSN
$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

// Security settings
ini_set('display_errors', 0); // Disable error display in production
ini_set('log_errors', 1);     // Enable error logging

// File upload settings
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('max_execution_time', 300); // 5 minutes
ini_set('memory_limit', '256M');

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Create database and table if they don't exist
try {
    // First, connect without specifying database
    $tempDsn = "mysql:host=$host;charset=utf8mb4";
    $tempPdo = new PDO($tempDsn, $dbUser, $dbPass, $options);
    
    // Create database if it doesn't exist
    $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname`");
    
    // Now connect to the specific database
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    // Create files table if it doesn't exist
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id VARCHAR(32) UNIQUE NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size BIGINT NOT NULL,
            mime_type VARCHAR(100),
            password_hash VARCHAR(255),
            expiry_date DATETIME,
            upload_date DATETIME NOT NULL,
            last_download DATETIME,
            download_count INT DEFAULT 0,
            is_deleted BOOLEAN DEFAULT FALSE,
            INDEX idx_file_id (file_id),
            INDEX idx_expiry_date (expiry_date),
            INDEX idx_upload_date (upload_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $pdo->exec($createTableSQL);
    
    // Create cleanup procedure for expired files
    $cleanupProcedure = "
        CREATE EVENT IF NOT EXISTS cleanup_expired_files
        ON SCHEDULE EVERY 1 HOUR
        DO
        BEGIN
            -- Mark expired files as deleted
            UPDATE files 
            SET is_deleted = TRUE 
            WHERE expiry_date IS NOT NULL 
            AND expiry_date < NOW() 
            AND is_deleted = FALSE;
            
            -- Delete files older than 30 days that are marked as deleted
            DELETE FROM files 
            WHERE is_deleted = TRUE 
            AND upload_date < DATE_SUB(NOW(), INTERVAL 30 DAY);
        END
    ";
    
    // Enable event scheduler (may require appropriate privileges)
    try {
        $pdo->exec("SET GLOBAL event_scheduler = ON");
        $pdo->exec($cleanupProcedure);
    } catch (PDOException $e) {
        // Event scheduler might not be available or user might not have privileges
        // This is non-critical, so we'll continue
        error_log("Could not create cleanup event: " . $e->getMessage());
    }
    
} catch (PDOException $e) {
    error_log("Database setup error: " . $e->getMessage());
    die("Database connection failed. Please check your configuration.");
}

// Cleanup function for manual execution
function cleanupExpiredFiles() {
    global $pdo;
    
    try {
        // Get expired files
        $stmt = $pdo->prepare("
            SELECT file_path FROM files 
            WHERE expiry_date IS NOT NULL 
            AND expiry_date < NOW() 
            AND is_deleted = FALSE
        ");
        $stmt->execute();
        $expiredFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Delete physical files
        foreach ($expiredFiles as $filePath) {
            $fullPath = 'uploads/' . $filePath;
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }
        
        // Mark as deleted in database
        $stmt = $pdo->prepare("
            UPDATE files 
            SET is_deleted = TRUE 
            WHERE expiry_date IS NOT NULL 
            AND expiry_date < NOW() 
            AND is_deleted = FALSE
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
        
    } catch (PDOException $e) {
        error_log("Cleanup error: " . $e->getMessage());
        return false;
    }
}

// Security headers
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Only set HSTS if using HTTPS
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Call security headers function
setSecurityHeaders();
?>