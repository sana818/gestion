<?php
// TOUJOURS EN PREMIER — avant tout require
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

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

ob_clean();

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

    // Vérifier le statut du compte
    $statut = $user['statut'] ?? '';
    if ($statut === 'en_attente') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Votre compte est en attente de validation par un administrateur.'
        ]);
        exit();
    }
    if ($statut === 'inactif') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Votre compte a été désactivé. Contactez l\'administration.'
        ]);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
    $role = strtolower(trim($user['role'] ?? ''));

    $payload = [
        "id"         => $user['id'],
        "email"      => $user['email'],
        "nom"        => $user['nom'],
        "prenom"     => $user['prenom'],
        "role"       => $user['role'],
        "poste"      => $user['poste'] ?? null,
        "statut"     => $user['statut'],
        "fid_code"   => $user['fid_code'] ?? null,
        "iat"        => time(),
        "exp"        => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, "HS256");

    $redirect = match($role) {
        'directeur'      => 'directeur.html',
        'responsable_rh' => 'admin_dashboard.html',
        default          => 'profile1.html'
    };

    echo json_encode([
        'success'    => true,
        'token'      => $jwt,
        'id'         => $user['id'],
        'nom'        => $user['nom'],
        'prenom'     => $user['prenom'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'poste'      => $user['poste'] ?? null,
        'statut'     => $user['statut'],
        'fid_code'   => $user['fid_code'] ?? null,
        'photo_profil' => $user['photo_profil'] ?? null,
        'redirect'   => $redirect,
        'message'    => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur: ' . $e->getMessage()
    ]);
}