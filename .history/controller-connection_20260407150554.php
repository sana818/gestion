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

// OPTIONS request (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ✅ استقبال البيانات (يدعم JSON و FormData) */
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    $input = $_POST;
}

/* ✅ التحقق من البيانات */
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
        ob_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    if ($utilisateur['statut'] !== 'actif') {
        http_response_code(403);
        ob_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Compte en attente ou désactivé'
        ]);
        exit();
    }

    /* ✅ JWT */
    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    $payload = [
        "id"     => $utilisateur['id'],
        "email"  => $utilisateur['email'],
        "nom"    => $utilisateur['nom'],
        "prenom" => $utilisateur['prenom'],
        "role"   => $utilisateur['role'],
        "exp"    => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    /* ✅ redirect حسب الدور */
    if ($utilisateur['role'] === 'directeur') {
        $redirect = 'directeur.html';
    } elseif ($utilisateur['role'] === 'responsable_rh') {
        $redirect = 'admin_dashboard.html';
    } else {
        $redirect = 'profile1.html';
    }

    ob_clean();

    echo json_encode([
        'success'      => true,
        'token'        => $jwt,
        'id'           => $utilisateur['id'],
        'email'        => $utilisateur['email'],
        'nom'          => $utilisateur['nom'],
        'prenom'       => $utilisateur['prenom'],
        'role'         => $utilisateur['role'],
        'poste'        => $utilisateur['poste'] ?? '',
        'statut'       => $utilisateur['statut'] ?? '',
        'rfid_code'    => $utilisateur['rfid_code'] ?? '',
        'photo_profil' => $utilisateur['photo_profil'] 
                            ? base64_encode($utilisateur['photo_profil']) 
                            : '',
        'redirect'     => $redirect
    ]);

} catch (Exception $e) {

    http_response_code(500);
    ob_clean();

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}