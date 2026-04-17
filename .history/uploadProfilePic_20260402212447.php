<?php
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ── JWT ── */
$headers    = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header manquant"]);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token JWT manquant ou mal forme"]);
    exit;
}

$jwt        = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId  = $decoded->id ?? null;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide : " . $e->getMessage()]);
    exit;
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "ID utilisateur introuvable dans le token"]);
    exit;
}

/* ── Upload ── */
try {
    if (empty($_FILES['photo'])) {
        throw new Exception('Aucun fichier uploade');
    }

    $photo = $_FILES['photo'];

    if ($photo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erreur lors de l upload');
    }

    $mime = mime_content_type($photo['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
        throw new Exception('Format non supporte (JPEG, PNG, GIF uniquement)');
    }

    if ($photo['size'] > 2 * 1024 * 1024) {
        throw new Exception('Fichier trop volumineux (max 2MB)');
    }

    $photoData = file_get_contents($photo['tmp_name']);

    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    
    $stmt->execute([$photoData, $userId]);

    echo json_encode([
        'success'   => true,
        'message'   => 'Photo mise a jour avec succes',
        'photo_url' => 'data:' . $mime . ';base64,' . base64_encode($photoData)
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}