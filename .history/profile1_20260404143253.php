<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";   // ← Change par ta vraie clé !

// ====================== RÉCUPÉRATION TOKEN (plusieurs méthodes) ======================
$authHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant ou mal formé"]);
    exit;
}

$jwt = $matches[1];

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId = $decoded->id ?? null;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide : " . $e->getMessage()]);
    exit;
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "ID utilisateur introuvable"]);
    exit;
}

// ====================== BASE DE DONNÉES ======================
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("
        SELECT id, nom, prenom, email, numero_telephone, photo_profil, role, poste, statut 
        FROM employes 
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur non trouvé"]);
        exit;
    }

    // Photo de profil
    if (!empty($user['photo_profil']) && strlen($user['photo_profil']) > 100) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($user['photo_profil']) ?: 'image/jpeg';
        $user['photo_profil'] = 'data:' . $mime . ';base64,' . base64_encode($user['photo_profil']);
    } else {
        $prenom = $user['prenom'] ?? 'J';
        $nom = $user['nom'] ?? 'D';
        $user['photo_profil'] = "https://ui-avatars.com/api/?name=" . urlencode($prenom . "+" . $nom) . "&size=180&background=4a6fa5&color=fff";
    }

    echo json_encode([
        "success" => true,
        "user"    => $user
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Erreur serveur : " . $e->getMessage()
    ]);
}
?>