<?php
// === CONFIGURATION ERREURS (à enlever en production) ===
ini_set('display_errors', 1);
error_reporting(E_ALL);

// === HEADERS ===
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// === AUTOLOAD (très important) ===
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Composer non installé. Exécutez "composer install" dans le dossier du projet.'
    ]);
    exit();
}
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;

// === INCLUDES ===
require_once 'model.php';   // contient Database + classe User

// === DONNÉES POST ===
$input = json_decode(file_get_contents('php://input'), true);

$email        = trim($input['email'] ?? '');
$mot_de_passe = $input['mot_de_passe'] ?? '';

if (empty($email) || empty($mot_de_passe)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email et mot de passe requis']);
    exit();
}

try {
    $user = User::findByEmail($email);
    
    if (!$user || !password_verify($mot_de_passe, $user['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email ou mot de passe incorrect']);
        exit();
    }

    // Optionnel : vérifier que le compte est actif
    if (($user['statut'] ?? 'en_attente') !== 'actif') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Compte non activé']);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#"; // ← change ça en production !

    $payload = [
        "id"    => $user['id'],
        "email" => $user['email'],
        "nom"   => $user['nom'],
        "role"  => $user['role'],
        "poste" => $user['poste'] ?? null,
        "iat"   => time(),
        "exp"   => time() + 3600 * 24   // 24h par exemple
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    echo json_encode([
        'success' => true,
        'token'   => $jwt,
        'user'    => [
            'id'    => $user['id'],
            'nom'   => $user['nom'],
            'email' => $user['email'],
            'role'  => $user['role'],
            'poste' => $user['poste'] ?? null
        ],
        'message' => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    error_log("Erreur login : " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur : ' . $e->getMessage()
    ]);
}