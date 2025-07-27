<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
require_once 'config.php';

// Create uploads directory if it doesn't exist
$uploadDir = 'uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

try {
    // Check if file was uploaded
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error occurred.');
    }

    $file = $_FILES['file'];
    
    // Validate file size (100MB max)
    $maxSize = 100 * 1024 * 1024; // 100MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File size exceeds 100MB limit.');
    }

    // Generate unique file ID and secure filename
    $fileId = generateUniqueId();
    $originalName = $file['name'];
    $fileExtension = pathinfo($originalName, PATHINFO_EXTENSION);
    $hashedFileName = hash('sha256', $fileId . time()) . '.' . $fileExtension;
    $filePath = $uploadDir . $hashedFileName;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception('Failed to save uploaded file.');
    }

    // Process optional parameters
    $password = isset($_POST['password']) ? $_POST['password'] : null;
    $hashedPassword = $password ? password_hash($password, PASSWORD_DEFAULT) : null;
    
    $expiryDate = isset($_POST['expiry']) ? $_POST['expiry'] : null;
    if ($expiryDate) {
        $expiryDateTime = new DateTime($expiryDate);
        $expiryDate = $expiryDateTime->format('Y-m-d H:i:s');
    }

    // Save file info to database
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    $stmt = $pdo->prepare("
        INSERT INTO files (file_id, original_name, file_path, file_size, mime_type, password_hash, expiry_date, upload_date, download_count) 
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 0)
    ");
    
    $stmt->execute([
        $fileId,
        $originalName,
        $hashedFileName,
        $file['size'],
        $file['type'],
        $hashedPassword,
        $expiryDate
    ]);

    // Return success response
    echo json_encode([
        'success' => true,
        'fileId' => $fileId,
        'originalName' => $originalName,
        'fileSize' => $file['size'],
        'hasPassword' => !empty($password),
        'expiryDate' => $expiryDate,
        'message' => 'File uploaded successfully!'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function generateUniqueId() {
    return bin2hex(random_bytes(16));
}

function sanitizeFileName($filename) {
    // Remove dangerous characters
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    return $filename;
}
?>