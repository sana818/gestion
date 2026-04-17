<?php
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
    echo json_encode(['success' => false, 'message' => 'Email et mot de passe requis']);
    exit;
}

$email        = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

$utilisateur = User::findByEmail($email);
if (!$utilisateur || !password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Identifiants incorrects']);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

$payload = [
    "id"     => $utilisateur['id'],
    "email"  => $utilisateur['email'],
    "nom"    => $utilisateur['nom'],
    "prenom" => $utilisateur['prenom'],
    "role"   => $utilisateur['role'],   // valeur brute de la BDD : "directeur", "responsable_rh", "employe"
    "poste"  => $utilisateur['poste'] ?? null,
    "exp"    => time() + 3600
];

$jwt = JWT::encode($payload, $secret_key, "HS256");

// ── Redirection selon le rôle (comparaison insensible à la casse) ──
$role = strtolower(trim($utilisateur['role'] ?? ''));

if ($role === 'directeur') {
    $redirect = 'directeur.html';   // ← page directeur
} elseif ($role === 'responsable_rh') {
    $redirect = 'admin_dashboard.html';       // ← page responsable RH
} else {
    $redirect = 'profile1.html';              // ← page employé
}

echo json_encode([
    'success' => true,
    'token'   => $jwt,
    'email'   => $utilisateur['email'],
    'nom'     => $utilisateur['nom'],
    'prenom'  => $utilisateur['prenom'],
    'role'    => $utilisateur['role'],
    'poste'   => $utilisateur['poste'] ?? null,
    'redirect'=> $redirect
]);
?>