<?php
require_once 'model.php'; // Classe User avec findByEmail
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json');

// Récupérer les données POST
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$mot_de_passe = $input['mot_de_passe'] ?? '';

if (!$email || !$mot_de_passe) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email et mot de passe requis']);
    exit();
}

try {
    $user = User::findByEmail($email);
    if (!$user || !password_verify($mot_de_passe, $user['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Email ou mot de passe incorrect']);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    $payload = [
        "id" => $user['id'],
        "email" => $user['email'],
        "nom" => $user['nom'],
        "role" => $user['role'],
        "iat" => time(),
        "exp" => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    echo json_encode([
        'success' => true,
        'token' => $jwt,
        'id' => $user['id'],
        'nom' => $user['nom'],
        'email' => $user['email'],
        'role' => $user['role'],
        'message' => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}