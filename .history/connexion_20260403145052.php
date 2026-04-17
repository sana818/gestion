<?php
// 1. Désactiver display_errors EN PREMIER (avant tout output)
ini_set('display_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1'); // log dans error_log au lieu d'afficher

// 2. Output buffering pour capturer les erreurs inattendues
ob_start();

require_once 'vendor/autoload.php';
require_once 'model.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); exit();
}

// 3. Vider le buffer avant d'écrire du JSON
ob_clean();

$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$mot_de_passe = $input['mot_de_passe'] ?? '';

if (empty($email) || empty($mot_de_passe)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Email et mot de passe requis'
    ]);
    exit();
}

try {
    $user = User::findByEmail($email);

    if (!$user || !password_verify($mot_de_passe, $user['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
    $role = strtolower(trim($user['role'] ?? ''));

    $payload = [
        "id"     => $user['id'],
        "email"  => $user['email'],
        "nom"    => $user['nom'],
        "prenom" => $user['prenom'] ?? null,
        "role"   => $user['role'],
        "poste"  => $user['poste'] ?? null,
        "iat"    => time(),
        "exp"    => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, "HS256");

    $redirect = match($role) {
        'directeur'      => 'directeur.html',
        'responsable_rh' => 'admin_dashboard.html',
        default           => 'profile1.html'
    };

    echo json_encode([
        'success'  => true,
        'token'    => $jwt,
        'id'       => $user['id'],
        'nom'      => $user['nom'],
        'prenom'   => $user['prenom'] ?? null,
        'email'    => $user['email'],
        'role'     => $user['role'],
        'poste'    => $user['poste'] ?? null,
        'redirect' => $redirect,
        'message'  => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur: ' . $e->getMessage()
    ]);
}