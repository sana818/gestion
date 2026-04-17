<?php
ob_start();
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once 'Database.php';
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

/* ✅ قراءة البيانات */
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

/* fallback */
if (json_last_error() !== JSON_ERROR_NONE || empty($input)) {
    $input = $_POST;
}

/* ✅ check */
if (empty($input['email']) || empty($input['password'])) {
    http_response_code(400);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Email et mot de passe requis'
    ]);
    exit();
}

$email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
$password = $input['password'];

try {

    $utilisateur = User::findByEmail($email);

    if (!$utilisateur || !password_verify($password, $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    if ($utilisateur['statut'] !== 'actif') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Compte désactivé'
        ]);
        exit();
    }

    $secret_key = "SECRET123";

    $payload = [
        "id" => $utilisateur['id'],
        "email" => $utilisateur['email'],
        "role" => $utilisateur['role'],
        "exp" => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

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