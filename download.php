<?php
session_start();
require_once 'config.php';

$fileId = $_GET['id'] ?? '';
$password = $_POST['password'] ?? '';

if (empty($fileId)) {
    http_response_code(400);
    die('File ID is required.');
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    // Get file information
    $stmt = $pdo->prepare("SELECT * FROM files WHERE file_id = ? AND (expiry_date IS NULL OR expiry_date > NOW())");
    $stmt->execute([$fileId]);
    $fileInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fileInfo) {
        http_response_code(404);
        die('File not found or has expired.');
    }

    // Check if file exists on disk
    $filePath = 'uploads/' . $fileInfo['file_path'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        die('File not found on server.');
    }

    // Handle password protection
    if ($fileInfo['password_hash']) {
        if (empty($password)) {
            // Show password form
            showPasswordForm($fileId, $fileInfo);
            exit;
        } else {
            // Verify password
            if (!password_verify($password, $fileInfo['password_hash'])) {
                showPasswordForm($fileId, $fileInfo, 'Incorrect password. Please try again.');
                exit;
            }
        }
    }

    // Update download count
    $stmt = $pdo->prepare("UPDATE files SET download_count = download_count + 1, last_download = NOW() WHERE file_id = ?");
    $stmt->execute([$fileId]);

    // Serve the file
    header('Content-Description: File Transfer');
    header('Content-Type: ' . ($fileInfo['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($fileInfo['original_name']) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . $fileInfo['file_size']);

    // Output file
    readfile($filePath);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    die('Server error occurred.');
}

function showPasswordForm($fileId, $fileInfo, $error = '') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>SecureShare - Password Required</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .container {
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(10px);
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                padding: 40px;
                width: 100%;
                max-width: 500px;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .container::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #667eea, #764ba2, #f093fb);
                background-size: 200% 100%;
                animation: gradient 3s ease infinite;
            }

            @keyframes gradient {
                0%, 100% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
            }

            .logo {
                font-size: 2.5rem;
                font-weight: bold;
                background: linear-gradient(135deg, #667eea, #764ba2);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
                margin-bottom: 10px;
            }

            .lock-icon {
                font-size: 4rem;
                color: #667eea;
                margin-bottom: 20px;
            }

            .file-info {
                background: #f8f9fa;
                border-radius: 15px;
                padding: 20px;
                margin-bottom: 25px;
                border-left: 4px solid #667eea;
            }

            .file-name {
                font-weight: bold;
                font-size: 1.1rem;
                color: #333;
                margin-bottom: 8px;
                word-break: break-all;
            }

            .file-details {
                color: #666;
                font-size: 0.9rem;
            }

            .password-form {
                margin-top: 25px;
            }

            .form-input {
                width: 100%;
                padding: 15px;
                border: 2px solid #e1e5e9;
                border-radius: 12px;
                font-size: 1rem;
                margin-bottom: 20px;
                transition: all 0.3s ease;
                background: white;
            }

            .form-input:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }

            .download-btn {
                width: 100%;
                padding: 15px;
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                border: none;
                border-radius: 12px;
                font-size: 1.1rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .download-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
            }

            .error-message {
                background: #ffebee;
                color: #c62828;
                padding: 15px;
                border-radius: 10px;
                margin-bottom: 20px;
                border-left: 4px solid #c62828;
            }

            .back-link {
                margin-top: 20px;
                color: #667eea;
                text-decoration: none;
                font-size: 0.9rem;
            }

            .back-link:hover {
                text-decoration: underline;
            }

            @media (max-width: 768px) {
                .container {
                    padding: 25px;
                    margin: 10px;
                }
                
                .logo {
                    font-size: 2rem;
                }
                
                .lock-icon {
                    font-size: 3rem;
                }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="logo">🔐 SecureShare</div>
            
            <div class="lock-icon">🔒</div>
            <h2>Password Required</h2>
            <p>This file is password protected. Please enter the password to download.</p>

            <div class="file-info">
                <div class="file-name"><?php echo htmlspecialchars($fileInfo['original_name']); ?></div>
                <div class="file-details">
                    Size: <?php echo formatFileSize($fileInfo['file_size']); ?> | 
                    Downloads: <?php echo $fileInfo['download_count']; ?>
                    <?php if ($fileInfo['expiry_date']): ?>
                        | Expires: <?php echo date('M j, Y g:i A', strtotime($fileInfo['expiry_date'])); ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="password-form">
                <input type="password" name="password" class="form-input" placeholder="Enter password" required autofocus>
                <button type="submit" class="download-btn">🔓 Unlock & Download</button>
            </form>

            <a href="index.html" class="back-link">← Upload a new file</a>
        </div>
    </body>
    </html>
    <?php
}

function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = array('Bytes', 'KB', 'MB', 'GB', 'TB');
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}
?>