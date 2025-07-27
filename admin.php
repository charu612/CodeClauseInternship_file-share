<?php
session_start();
require_once 'config.php';

// Simple authentication (change these credentials!)
$adminUsername = 'admin';
$adminPassword = 'admin123'; // Change this!

// Handle login
if (isset($_POST['login'])) {
    if ($_POST['username'] === $adminUsername && $_POST['password'] === $adminPassword) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid credentials';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check if logged in
if (!isset($_SESSION['admin_logged_in'])) {
    showLoginForm($loginError ?? '');
    exit;
}

// Handle file deletion
if (isset($_POST['delete_file'])) {
    $fileId = $_POST['file_id'];
    $stmt = $pdo->prepare("SELECT file_path FROM files WHERE file_id = ?");
    $stmt->execute([$fileId]);
    $file = $stmt->fetch();
    
    if ($file) {
        // Delete physical file
        $filePath = 'uploads/' . $file['file_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Mark as deleted in database
        $stmt = $pdo->prepare("UPDATE files SET is_deleted = TRUE WHERE file_id = ?");
        $stmt->execute([$fileId]);
        
        $message = 'File deleted successfully';
    }
}

// Handle cleanup
if (isset($_POST['cleanup'])) {
    $cleaned = cleanupExpiredFiles();
    $message = "Cleaned up {$cleaned} expired files";
}

// Get statistics
$pdo = new PDO($dsn, $dbUser, $dbPass, $options);

// Get files with pagination
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$stmt = $pdo->prepare("
    SELECT * FROM files 
    WHERE is_deleted = FALSE 
    ORDER BY upload_date DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute([$limit, $offset]);
$files = $stmt->fetchAll();

// Get total count for pagination
$stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = FALSE");
$totalFiles = $stmt->fetchColumn();
$totalPages = ceil($totalFiles / $limit);

// Get statistics
$stats = getSystemStats($pdo);

function showLoginForm($error) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login - SecureShare</title>
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 15px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 400px;
            }
            .login-title {
                text-align: center;
                margin-bottom: 30px;
                color: #333;
                font-size: 1.5rem;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-input {
                width: 100%;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 8px;
                font-size: 1rem;
            }
            .form-input:focus {
                outline: none;
                border-color: #667eea;
            }
            .login-btn {
                width: 100%;
                padding: 12px;
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 1rem;
                cursor: pointer;
            }
            .error {
                background: #ffebee;
                color: #c62828;
                padding: 10px;
                border-radius: 5px;
                margin-bottom: 20px;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h2 class="login-title">🔐 Admin Login</h2>
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <input type="text" name="username" class="form-input" placeholder="Username" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password" class="form-input" placeholder="Password" required>
                </div>
                <button type="submit" name="login" class="login-btn">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

function getSystemStats($pdo) {
    $stats = [];
    
    // Total files
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE is_deleted = FALSE");
    $stats['total_files'] = $stmt->fetchColumn();
    
    // Total storage
    $stmt = $pdo->query("SELECT SUM(file_size) FROM files WHERE is_deleted = FALSE");
    $stats['total_storage'] = $stmt->fetchColumn() ?: 0;
    
    // Total downloads
    $stmt = $pdo->query("SELECT SUM(download_count) FROM files WHERE is_deleted = FALSE");
    $stats['total_downloads'] = $stmt->fetchColumn() ?: 0;
    
    // Files today
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE DATE(upload_date) = CURDATE() AND is_deleted = FALSE");
    $stats['today_uploads'] = $stmt->fetchColumn();
    
    // Expired files
    $stmt = $pdo->query("SELECT COUNT(*) FROM files WHERE expiry_date IS NOT NULL AND expiry_date < NOW() AND is_deleted = FALSE");
    $stats['expired_files'] = $stmt->fetchColumn();
    
    return $stats;
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - SecureShare</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
        }

        .header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .actions {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }

        .actions h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .action-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-right: 10px;
            font-size: 0.9rem;
        }

        .action-btn:hover {
            background: #5a6fd8;
        }

        .files-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
        }

        .file-name {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8rem;
        }

        .delete-btn:hover {
            background: #c82333;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        .pagination a {
            padding: 8px 12px;
            margin: 0 5px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 5px;
            border: 1px solid #ddd;
        }

        .pagination a:hover,
        .pagination a.current {
            background: #667eea;
            color: white;
        }

        .message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .expired {
            color: #dc3545;
            font-weight: bold;
        }

        .protected {
            color: #ffc107;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .header-content {
                flex-direction: column;
                gap: 10px;
            }
            
            table {
                font-size: 0.8rem;
            }
            
            .file-name {
                max-width: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <div class="logo">🔐 SecureShare Admin</div>
            <a href="?logout" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if (isset($message)): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_files']); ?></div>
                <div class="stat-label">Total Files</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo formatFileSize($stats['total_storage']); ?></div>
                <div class="stat-label">Storage Used</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['total_downloads']); ?></div>
                <div class="stat-label">Total Downloads</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo number_format($stats['today_uploads']); ?></div>
                <div class="stat-label">Uploaded Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number <?php echo $stats['expired_files'] > 0 ? 'expired' : ''; ?>">
                    <?php echo number_format($stats['expired_files']); ?>
                </div>
                <div class="stat-label">Expired Files</div>
            </div>
        </div>

        <div class="actions">
            <h3>🛠️ System Actions</h3>
            <form method="POST" style="display: inline;">
                <button type="submit" name="cleanup" class="action-btn" onclick="return confirm('Clean up expired files?')">
                    🧹 Cleanup Expired Files
                </button>
            </form>
            <a href="index.html" class="action-btn" style="text-decoration: none; display: inline-block;">
                📤 Upload Interface
            </a>
        </div>

        <div class="files-table">
            <div class="table-header">
                <h3>📁 Recent Files (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)</h3>
            </div>
            
            <?php if (empty($files)): ?>
                <div style="padding: 40px; text-align: center; color: #666;">
                    <p>No files found.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>Upload Date</th>
                            <th>Downloads</th>
                            <th>Expiry</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($files as $file): ?>
                            <tr>
                                <td class="file-name" title="<?php echo htmlspecialchars($file['original_name']); ?>">
                                    <?php echo htmlspecialchars($file['original_name']); ?>
                                </td>
                                <td><?php echo formatFileSize($file['file_size']); ?></td>
                                <td><?php echo date('M j, Y H:i', strtotime($file['upload_date'])); ?></td>
                                <td><?php echo number_format($file['download_count']); ?></td>
                                <td>
                                    <?php if ($file['expiry_date']): ?>
                                        <span class="<?php echo strtotime($file['expiry_date']) < time() ? 'expired' : ''; ?>">
                                            <?php echo date('M j, Y H:i', strtotime($file['expiry_date'])); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #28a745;">Never</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($file['password_hash']): ?>
                                        <span class="protected" title="Password Protected">🔒</span>
                                    <?php endif; ?>
                                    <?php if ($file['expiry_date'] && strtotime($file['expiry_date']) < time()): ?>
                                        <span class="expired" title="Expired">⚠️</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="download.php?id=<?php echo htmlspecialchars($file['file_id']); ?>" 
                                       target="_blank" 
                                       style="color: #667eea; text-decoration: none; margin-right: 10px;" 
                                       title="Download">📥</a>
                                    
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Delete this file permanently?')">
                                        <input type="hidden" name="file_id" value="<?php echo htmlspecialchars($file['file_id']); ?>">
                                        <button type="submit" name="delete_file" class="delete-btn" title="Delete">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>">« Previous</a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="?page=<?php echo $i; ?>" class="<?php echo $i == $page ? 'current' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>">Next »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh stats every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
        
        // Confirm delete actions
        document.querySelectorAll('form[onsubmit]').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to delete this file? This action cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>