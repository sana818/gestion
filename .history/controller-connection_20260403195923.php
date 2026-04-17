<?php
ini_set('display_errors', '1'); // خليها 1 للتجربة
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
require_once 'model.php';

use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// قراءة JSON
$json = file_get_contents('php://input');

if (!$json) {
    echo json_encode([
        'success' => false,
        'error' => 'Aucune donnée reçue'
    ]);
    exit();
}

$data = json_decode($json, true);

if ($data === null) {
    echo json_encode([
        'success' => false,
        'error' => 'JSON invalide'
    ]);
    exit();
}

// تحقق من المدخلات
if (empty($data['email']) || empty($data['mot_de_passe'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Email et mot de passe requis'
    ]);
    exit();
}

$email        = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

try {
    $utilisateur = User::findByEmail($email);

    if (!$utilisateur || !password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    $statut = strtolower(trim($utilisateur['statut'] ?? ''));

    if ($statut === 'en_attente') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Votre compte est en attente de validation'
        ]);
        exit();
    }

    if ($statut === 'refuse') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Compte refusé'
        ]);
        exit();
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
    $role = strtolower(trim($utilisateur['role'] ?? ''));

    if ($role === 'directeur') {
        $redirect = 'directeur.html';
    } elseif ($role === 'responsable_rh') {
        $redirect = 'admin_dashboard.html';
    } else {
        $redirect = 'profile1.html';
    }

    $payload = [
        "id"            => $utilisateur['id'],
        "email"         => $utilisateur['email'],
        "nom"           => $utilisateur['nom'],
        "prenom"        => $utilisateur['prenom'],
        "role"          => $utilisateur['role'],
        "poste"         => $utilisateur['poste'] ?? null,
        "statut"        => $utilisateur['statut'],
        "fid_code"      => $utilisateur['fid_code'] ?? null,
        "date_embauche" => $utilisateur['date_embauche'] ?? null,
        "iat"           => time(),
        "exp"           => time() + 3600
    ];

    $jwt = JWT::encode($payload, $secret_key, "HS256");

    echo json_encode([
        'success'       => true,
        'token'         => $jwt,
        'id'            => $utilisateur['id'],
        'nom'           => $utilisateur['nom'],
        'prenom'        => $utilisateur['prenom'],
        'email'         => $utilisateur['email'],
        'role'          => $utilisateur['role'],
        'poste'         => $utilisateur['poste'] ?? null,
        'statut'        => $utilisateur['statut'],
        'fid_code'      => $utilisateur['fid_code'] ?? null,
        'date_embauche' => $utilisateur['date_embauche'] ?? null,
        'photo_profil'  => $utilisateur['photo_profil'] ?? null,
        'redirect'      => $redirect,
        'message'       => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur: ' . $e->getMessage()
    ]);
}