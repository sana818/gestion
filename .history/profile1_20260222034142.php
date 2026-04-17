<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Gestion personnalisée des erreurs
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur : " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erreur PHP : $errstr dans $errfile ligne $errline"
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Fonction pour les headers
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

// Authentification
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (empty($authHeader)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header manquant"]);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token JWT manquant ou mal formé"]);
    exit;
}

$jwt = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide : " . $e->getMessage()]);
    exit;
}

if (!isset($decoded->id)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Le token ne contient pas d'ID utilisateur"]);
    exit;
}

$userId = $decoded->id;

// Connexion à la base de données
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Requête avec jointure - PRIORITÉ À emplois.poste
    $stmt = $pdo->prepare("
        SELECT 
            r.id, 
            r.nom, 
            r.prenom, 
            r.date_naissance, 
            r.email,
            r.numero_telephone,
            r.photo_profil,
            r.role,
            COALESCE(e.poste, r.poste) as poste,
            e.date_embauche
        FROM registre r
        LEFT JOIN emplois e ON r.id = e.employe_id
        WHERE r.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur non trouvé"]);
        exit;
    }

    // Gestion des valeurs nulles
    $user = array_map(function($v) {
        return $v === null ? '' : $v;
    }, $user);

    // ===== CORRECTION ICI : Gestion de la photo de profil =====
    if (!empty($user['photo_profil'])) {
        // Convertir le BLOB en base64 sans spécifier le type MIME
        // Le navigateur détectera automatiquement le type (JPEG, PNG, GIF, etc.)
        $user['photo_profil'] = 'data:;base64,' . base64_encode($user['photo_profil']);
    } else {
        // Avatar par défaut avec les initiales de l'utilisateur
        $prenom = !empty($user['prenom']) ? $user['prenom'] : 'Jean';
        $nom = !empty($user['nom']) ? $user['nom'] : 'Dupont';
        $user['photo_profil'] = 'https://ui-avatars.com/api/?name=' . urlencode($prenom . ' ' . $nom) . '&size=180&background=2c3e50&color=fff&bold=true';
    }
    // ===== FIN DE LA CORRECTION =====

    echo json_encode([
        "success" => true,
        "user" => $user
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur base de données : " . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur inattendue : " . $e->getMessage()]);
}
?>