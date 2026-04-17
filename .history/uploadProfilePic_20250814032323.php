<?php
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');

// Authentification (même code que profile1.php)
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header manquant"]);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token JWT manquant ou mal formé"]);
    exit;
}

$jwt = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId = $decoded->id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide : " . $e->getMessage()]);
    exit;
}

try {
    if (empty($_FILES['photo'])) {
        throw new Exception('Aucun fichier uploadé');
    }
    
    $photo = $_FILES['photo'];
    
    // Validation
    if ($photo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erreur lors de l\'upload');
    }
    
    // Vérifier le type MIME
    $mime = mime_content_type($photo['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
        throw new Exception('Format d\'image non supporté (JPEG, PNG, GIF uniquement)');
    }
    
    // Taille maximale 2MB
    if ($photo['size'] > 2 * 1024 * 1024) {
        throw new Exception('Fichier trop volumineux (max 2MB)');
    }
    
    // Lire le contenu du fichier
    $photoData = file_get_contents($photo['tmp_name']);
    
    // Mettre à jour en base de données
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $stmt = $pdo->prepare("UPDATE registre SET photo_profil = ? WHERE id = ?");
    $stmt->execute([$photoData, $userId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Photo mise à jour avec succès',
        'photo_url' => 'data:'.$mime.';base64,'.base64_encode($photoData)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}