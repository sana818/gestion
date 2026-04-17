<?php
require_once 'Database.php';
echo json_encode(['success' => false, 'error' => 'test2']);
exit();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once 'Database.php';
require_once 'model.php';
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode non autorisee']);
    exit();
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['email']) || empty($data['mot_de_passe'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email et mot de passe requis']);
    exit();
}

$email        = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

try {
    $utilisateur = User::findByEmail($email);

    if (!$utilisateur || !password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email ou mot de passe incorrect']);
        exit();
    }

    $statut = strtolower(trim($utilisateur['statut'] ?? ''));

    if ($statut === 'en_attente') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Compte en attente de validation.']);
        exit();
    }

    if ($statut === 'inactif') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Compte desactive. Contactez administration.']);
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
        "poste"         => $utilisateur['poste']         ?? null,
        "statut"        => $utilisateur['statut'],
        "fid_code"      => $utilisateur['fid_code']      ?? null,
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
        'poste'         => $utilisateur['poste']         ?? null,
        'statut'        => $utilisateur['statut'],
        'fid_code'      => $utilisateur['fid_code']      ?? null,
        'date_embauche' => $utilisateur['date_embauche'] ?? null,
        'photo_profil'  => $utilisateur['photo_profil']  ?? null,
        'redirect'      => $redirect,
        'message'       => 'Connexion reussie'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()]);
}