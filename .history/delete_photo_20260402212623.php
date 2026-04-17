<?php
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'vendor/autoload.php';
require_once 'Database.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function handleError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

try {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError('Token JWT manquant', 401);
    }

    $jwt = $matches[1];
    $secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    $userId = $decoded->id ?? null;
    
    if (!$userId) {
        handleError('ID utilisateur non trouvé', 401);
    }
    
    $db = Database::connect();
    
    // Récupérer l'ancienne photo
    $stmt = $db->prepare("SELECT photo_profil FROM employ WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Supprimer le fichier physique
    if ($user && $user['photo_profil']) {
        $photoPath = $user['photo_profil'];
        $pathsToCheck = [
            __DIR__ . '/' . $photoPath,
            __DIR__ . '/uploads/' . basename($photoPath),
            __DIR__ . '/../uploads/' . basename($photoPath)
        ];
        
        foreach ($pathsToCheck as $path) {
            if (file_exists($path)) {
                unlink($path);
                break;
            }
        }
    }
    
    // Mettre à jour la base de données
    $stmt = $db->prepare("UPDATE registre SET photo_profil = NULL WHERE id = ?");
    $stmt->execute([$userId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Photo supprimée avec succès'
    ]);

} catch (Exception $e) {
    handleError('Erreur: ' . $e->getMessage(), 500);
}
?>