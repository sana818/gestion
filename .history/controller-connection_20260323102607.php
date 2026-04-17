<?php
require_once 'vendor/autoload.php'; 
require_once 'model.php'; 
use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (empty($data['email']) || empty($data['mot_de_passe'])) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Email et mot de passe requis']);
    exit;
}

$email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
$mot_de_passe = $data['mot_de_passe'];

$utilisateur = User::findByEmail($email);
if (!$utilisateur || !password_verify($mot_de_passe, $utilisateur['mot_de_passe'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Identifiants incorrects']);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
$payload = [
    "id"=>$utilisateur['id'],
    "email"=>$utilisateur['email'],
    "nom"=>$utilisateur['nom'],
    "prenom"=>$utilisateur['prenom'],
    "role"=>$utilisateur['role'],
    "poste"=>$utilisateur['poste'] ?? null,
    "exp"=>time()+3600
];
$jwt = JWT::encode($payload, $secret_key, "HS256");

echo json_encode([
    'success'=>true,
    'token'=>$jwt,
    'email'=>$utilisateur['email'],
    'nom'=>$utilisateur['nom'],
    'prenom'=>$utilisateur['prenom'],
    'role'=>$utilisateur['role'],
    'poste'=>$utilisateur['poste'] ?? null,
    'redirect'=>$utilisateur['role']==='responsable_rh' ? 'admin_dashboard.html' : 'profile1.html'
]);
