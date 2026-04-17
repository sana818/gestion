<?php
require_once 'vendor/autoload.php';
require_once 'model.php';   // Assure-toi que User::findByEmail existe et query la table "employes"

use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");                    // À restreindre en production !
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Gestion préflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Mode debug (à désactiver en production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

$input = json_decode(file_get_contents('php://input'), true);

$email        = trim($input['email'] ?? '');
$mot_de_passe = $input['mot_de_passe'] ?? '';

// Validation des champs
if (empty($email) || empty($mot_de_passe)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Email et mot de passe sont requis'
    ]);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Format d\'email invalide'
    ]);
    exit();
}

try {
    // Récupération de l'utilisateur (table employes)
    $user = User::findByEmail($email);

    if (!$user || !password_verify($mot_de_passe, $user['mot_de_passe'] ?? '')) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error'   => 'Email ou mot de passe incorrect'
        ]);
        exit();
    }

    // === Clé secrète JWT ===
    // ⚠️  Change cette clé par une très longue et aléatoire !
    // Idéalement : utilise un fichier .env (vlucas/phpdotenv)
    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#ChangezLaVraiment2026";

    // Payload JWT amélioré
    $payload = [
        'iat'    => time(),                    // Issued At
        'exp'    => time() + 3600,             // Expiration (1 heure)
        'jti'    => bin2hex(random_bytes(16)), // JWT ID unique (contre replay attacks)
        'id'     => $user['id'],
        'email'  => $user['email'],
        'nom'    => $user['nom'] ?? '',
        'prenom' => $user['prenom'] ?? '',
        'role'   => $user['role'] ?? 'employe',   // Important : ajoute ce champ dans ta table employes
        'poste'  => $user['poste'] ?? $user['fid_code'] ?? 'Non renseigné',
        'statut' => $user['statut'] ?? null
    ];

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    // Détermination de la page de redirection
    $role = strtolower(trim($user['role'] ?? $user['poste'] ?? 'employe'));

    if (in_array($role, ['directeur', 'director', 'admin'])) {
        $redirect = 'directeur.html';
    } elseif (in_array($role, ['responsable_rh', 'rh', 'admin_rh'])) {
        $redirect = 'admin_dashboard.html';
    } else {
        $redirect = 'profile1.html';   // Par défaut pour les employés
    }

    // Réponse succès
    echo json_encode([
        'success'  => true,
        'token'    => $jwt,
        'id'       => $user['id'],
        'nom'      => $user['nom'] ?? '',
        'prenom'   => $user['prenom'] ?? '',
        'email'    => $user['email'],
        'role'     => $user['role'] ?? 'employe',
        'poste'    => $user['poste'] ?? 'Non renseigné',
        'redirect' => $redirect,
        'message'  => 'Connexion réussie'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erreur serveur : ' . $e->getMessage()
    ]);
}
?>