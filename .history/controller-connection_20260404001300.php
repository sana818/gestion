<?php
ob_start();
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
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Email et mot de passe requis']);
    exit();
}

$email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

try {
    $utilisateur = User::findByEmail($email);

    if (!$utilisateur || !password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Email ou mot de passe incorrect']);
        exit();
    }

    if ($utilisateur['statut'] !== 'actif') {
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Votre compte est en attente de validation ou désactivé.']);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    $payload = [
        "id"     => $utilisateur['id'],
        "email"  => $utilisateur['email'],
        "nom"    => $utilisateur['nom'],
        "prenom" => $utilisateur['prenom'],
        "role"   => $utilisateur['role'],
        "exp"    => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, "HS256");

    $role = $utilisateur['role'];
    if ($role === 'admin') {
        $redirect = 'admin_dashboard.html';
    } elseif ($role === 'directeur') {
        $redirect = 'dashboard-directeur.php';
    } elseif ($role === 'responsable_rh') {
        $redirect = 'dashboard-rh.php';
    } else {
        $redirect = 'dashboard-employe.php';
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
        'poste'        => $utilisateur['poste']  ?? '',
        'statut'       => $utilisateur['statut'] ?? '',
        'fid_code'     => $utilisateur['fid_code'] ?? '',
        'photo_profil' => $utilisateur['photo_profil'] ? base64_encode($utilisateur['photo_profil']) : '',
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