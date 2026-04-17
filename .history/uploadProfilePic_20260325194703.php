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

/* ───────── JWT ───────── */
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant"]);
    exit;
}

$jwt = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId = $decoded->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide"]);
    exit;
}

/* ───────── Upload ───────── */
try {

    if (!isset($_FILES['photo'])) {
        throw new Exception("Aucune image envoyée");
    }

    $file = $_FILES['photo'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Erreur upload");
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/gif'];

    if (!in_array($mime, $allowed)) {
        throw new Exception("Format non supporté");
    }

    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception("Image trop grande");
    }

    /* dossier */
    $uploadDir = __DIR__ . "/uploads/";
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    /* nom unique */
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = "avatar_" . $userId . "_" . time() . "." . $ext;
    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        throw new Exception("Erreur sauvegarde");
    }

    $photoURL = "uploads/" . $fileName;

    /* DB */
    $pdo = new PDO(
        "mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8",
        "root",
        "",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare("UPDATE registre SET photo_profil = ? WHERE id = ?");
    $stmt->execute([$photoURL, $userId]);

    echo json_encode([
        "success" => true,
        "photo_url" => $photoURL
    ]);

} catch (Exception $e) {

    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}