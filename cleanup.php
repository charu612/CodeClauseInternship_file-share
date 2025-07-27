<?php
/**
 * Cleanup Script for File Sharing Platform
 * 
 * This script should be run periodically (via cron job) to:
 * 1. Remove expired files
 * 2. Clean up orphaned files
 * 3. Generate usage statistics
 * 
 * Usage: php cleanup.php [--dry-run] [--verbose]
 */

require_once 'config.php';

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv);

if ($verbose) {
    echo "File Sharing Platform Cleanup Script\n";
    echo "====================================\n";
    echo "Mode: " . ($dryRun ? "DRY RUN" : "ACTIVE") . "\n\n";
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    // 1. Clean up expired files
    cleanupExpiredFiles($pdo, $dryRun, $verbose);
    
    // 2. Clean up orphaned files (files on disk but not in database)
    cleanupOrphanedFiles($pdo, $dryRun, $verbose);
    
    // 3. Clean up database entries for missing files
    cleanupMissingFiles($pdo, $dryRun, $verbose);
    
    // 4. Generate statistics
    if ($verbose) {
        generateStatistics($pdo);
    }
    
    if ($verbose) {
        echo "\nCleanup completed successfully!\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

function cleanupExpiredFiles($pdo, $dryRun, $verbose) {
    if ($verbose) echo "1. Cleaning up expired files...\n";
    
    // Get expired files
    $stmt = $pdo->query("
        SELECT id, file_path, original_name, expiry_date 
        FROM files 
        WHERE expiry_date IS NOT NULL 
        AND expiry_date < NOW() 
        AND is_deleted = FALSE
    ");
    
    $expiredFiles = $stmt->fetchAll();
    $deletedCount = 0;
    
    foreach ($expiredFiles as $file) {
        $filePath = 'uploads/' . $file['file_path'];
        
        if ($verbose) {
            echo "  - {$file['original_name']} (expired: {$file['expiry_date']})\n";
        }
        
        if (!$dryRun) {
            // Delete physical file
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // Mark as deleted in database
            $updateStmt = $pdo->prepare("UPDATE files SET is_deleted = TRUE WHERE id = ?");
            $updateStmt->execute([$file['id']]);
        }
        
        $deletedCount++;
    }
    
    if ($verbose) {
        echo "  Found {$deletedCount} expired files\n\n";
    }
}

function cleanupOrphanedFiles($pdo, $dryRun, $verbose) {
    if ($verbose) echo "2. Cleaning up orphaned files...\n";
    
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        if ($verbose) echo "  Upload directory doesn't exist, skipping...\n\n";
        return;
    }
    
    // Get all files from database
    $stmt = $pdo->query("SELECT file_path FROM files WHERE is_deleted = FALSE");
    $dbFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $dbFilesSet = array_flip($dbFiles);
    
    // Scan upload directory
    $diskFiles = scandir($uploadDir);
    $orphanedCount = 0;
    
    foreach ($diskFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        
        if (!isset($dbFilesSet[$file])) {
            if ($verbose) {
                echo "  - Orphaned file: {$file}\n";
            }
            
            if (!$dryRun) {
                unlink($uploadDir . $file);
            }
            
            $orphanedCount++;
        }
    }
    
    if ($verbose) {
        echo "  Found {$orphanedCount} orphaned files\n\n";
    }
}

function cleanupMissingFiles($pdo, $dryRun, $verbose) {
    if ($verbose) echo "3. Cleaning up database entries for missing files...\n";
    
    $stmt = $pdo->query("SELECT id, file_path, original_name FROM files WHERE is_deleted = FALSE");
    $dbFiles = $stmt->fetchAll();
    $missingCount = 0;
    
    foreach ($dbFiles as $file) {
        $filePath = 'uploads/' . $file['file_path'];
        
        if (!file_exists($filePath)) {
            if ($verbose) {
                echo "  - Missing file: {$file['original_name']}\n";
            }
            
            if (!$dryRun) {
                $updateStmt = $pdo->prepare("UPDATE files SET is_deleted = TRUE WHERE id = ?");
                $updateStmt->execute([$file['id']]);
            }
            
            $missingCount++;
        }
    }
    
    if ($verbose) {
        echo "  Found {$missingCount} missing files\n\n";
    }
}

function generateStatistics($pdo) {
    echo "4. System Statistics:\n";
    
    // Total files
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = FALSE");
    $totalFiles = $stmt->fetchColumn();
    
    // Total storage used
    $stmt = $pdo->query("SELECT SUM(file_size) FROM files WHERE is_deleted = FALSE");
    $totalStorage = $stmt->fetchColumn() ?: 0;
    
    // Password protected files
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE password_hash IS NOT NULL AND is_deleted = FALSE");
    $passwordProtected = $stmt->fetchColumn();
    
    // Files with expiry
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE expiry_date IS NOT NULL AND is_deleted = FALSE");
    $withExpiry = $stmt->fetchColumn();
    
    // Total downloads
    $stmt = $pdo->query("SELECT SUM(download_count) FROM files WHERE is_deleted = FALSE");
    $totalDownloads = $stmt->fetchColumn() ?: 0;
    
    // Files uploaded today
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE DATE(upload_date) = CURDATE() AND is_deleted = FALSE");
    $todayUploads = $stmt->fetchColumn();
    
    // Most popular file types
    $stmt = $pdo->query("
        SELECT 
            SUBSTRING_INDEX(original_name, '.', -1) as extension,
            COUNT(*) as count
        FROM files 
        WHERE is_deleted = FALSE 
        GROUP BY extension 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $popularTypes = $stmt->fetchAll();
    
    echo "  - Total active files: {$totalFiles}\n";
    echo "  - Total storage used: " . formatFileSize($totalStorage) . "\n";
    echo "  - Password protected: {$passwordProtected}\n";
    echo "  - With expiry date: {$withExpiry}\n";
    echo "  - Total downloads: {$totalDownloads}\n";
    echo "  - Uploaded today: {$todayUploads}\n";
    
    if ($popularTypes) {
        echo "  - Popular file types:\n";
        foreach ($popularTypes as $type) {
            echo "    * .{$type['extension']}: {$type['count']} files\n";
        }
    }
    
    echo "\n";
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>