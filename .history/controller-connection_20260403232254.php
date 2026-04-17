<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once 'model.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['email']) || empty($data['mot_de_passe'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email et mot de passe requis']);
    exit();
}

$email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

try {
    $utilisateur = User::findByEmail($email);

    if (!$utilisateur || !password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email ou mot de passe incorrect']);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    $payload = [
        "id" => $utilisateur['id'],
        "email" => $utilisateur['email'],
        "nom" => $utilisateur['nom'],
        "prenom" => $utilisateur['prenom'],
        "role" => $utilisateur['role'],
        "exp" => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, "HS256");

    echo json_encode([
        'success' => true,
        'token' => $jwt
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}