<?php
ob_start(); // 🔥 مهم لتفادي response vide

ini_set('display_errors', '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Vérifier autoload
if (!file_exists('vendor/autoload.php')) {
    echo json_encode([
        'success' => false,
        'error' => 'autoload.php introuvable'
    ]);
    exit();
}

require_once 'vendor/autoload.php';
require_once 'model.php';

use Firebase\JWT\JWT;

// Lire JSON
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

// Vérification champs
if (empty($data['email']) || empty($data['mot_de_passe'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Email et mot de passe requis'
    ]);
    exit();
}

$email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

try {

    // 🔥 test DB
    if (!isset($conn)) {
        throw new Exception("Connexion DB non disponible");
    }

    $utilisateur = User::findByEmail($email);

    if (!$utilisateur) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Utilisateur introuvable'
        ]);
        exit();
    }

    if (!password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Mot de passe incorrect'
        ]);
        exit();
    }

    $statut = strtolower(trim($utilisateur['statut'] ?? ''));

    if ($statut === 'en_attente') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Compte en attente de validation'
        ]);
        exit();
    }

    if ($statut === 'refuse') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Compte refusé'
        ]);
        exit();
    }

    // rôle
    $role = strtolower(trim($utilisateur['role'] ?? ''));

    if ($role === 'directeur') {
        $redirect = 'directeur.html';
    } elseif ($role === 'responsable_rh') {
        $redirect = 'admin_dashboard.html';
    } else {
        $redirect = 'profile1.html';
    }

    // JWT
    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

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
        'error'   => $e->getMessage()
    ]);
}

ob_end_flush(); // 🔥 مهم