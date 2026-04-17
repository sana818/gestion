<?php
require_once 'model.php'; // ta classe User avec findByEmail
require_once 'vendor/autoload.php'; 
use Firebase\JWT\JWT;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS")
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Activer l'affichage des erreurs pour le debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Récupérer les données POST
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$mot_de_passe = $input['mot_de_passe'] ?? '';

// Valider les données
if (empty($email) || empty($mot_de_passe)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Email et mot de passe requis'
    ]);
    exit();
}

try {
    // Rechercher l'utilisateur par email
    $user = User::findByEmail($email);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    // Vérifier le mot de passe
    if (!password_verify($mot_de_passe, $user['mot_de_passe'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    // Clé secrète pour signer le JWT
    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    // Créer le payload du token
    $payload = [
        "id" => $user['id'],
        "email" => $user['email'],
        "nom" => $user['nom'],
        "role" => $user['role'],
        "poste" => $user['poste'] ?? 'Non renseigné',
        "iat" => time(),
        "exp" => time() + 3600 // Expire dans 1 heure
    ];

    // Générer le token JWT
    $jwt = JWT::encode($payload, $secret_key, "HS256");

    // Réponse de succès
    echo json_encode([
        'success' => true,
        'token' => $jwt,
        'id' => $user['id'],
        'nom' => $user['nom'],
        'email' => $user['email'],
        'role' => $user['role'],
        'poste' => $user['poste'] ?? 'Non renseigné',
        'message' => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erreur serveur: ' . $e->getMessage()
    ]);
}
