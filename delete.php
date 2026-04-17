<?php
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

function handleError($message, $code = 400) {
    http_response_code($code);
    die(json_encode(['success' => false, 'error' => $message]));
}

try {
    // Vérification du JWT
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError('Token JWT manquant', 401);
    }

    $jwt = $matches[1];
    $secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));

    // Seul un directeur peut supprimer
    if (strtolower($decoded->role) !== 'directeur') {
        handleError('Action non autorisée', 403);
    }

    // Récupération de l'ID
    $id = $_GET['id'] ?? null;
    if (!$id || !is_numeric($id)) {
        handleError('ID utilisateur invalide');
    }

    // Connexion DB et suppression
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("DELETE FROM registre WHERE id = ?");
    $success = $stmt->execute([$id]);

    echo json_encode(['success' => $success]);

} catch (Exception $e) {
    handleError($e->getMessage(), 500);
}