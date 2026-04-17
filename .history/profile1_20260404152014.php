<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ===== AUTH =====
$authHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} else {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    ob_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant"]);
    exit;
}

$jwt        = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    ob_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide : " . $e->getMessage()]);
    exit;
}

$userId = $decoded->id;

// ===== DB =====
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tout est dans la table employes maintenant
    $stmt = $pdo->prepare("
        SELECT 
            id,
            nom,
            prenom,
            date_naissance,
            email,
            numero_telephone,
            photo_profil,
            role,
            poste,
            statut,
            rfid_code,
            date_embauche,
            created_at
        FROM employes
        WHERE id = ?
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ob_clean();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur non trouvé"]);
        exit;
    }

    // Gestion photo de profil
    if (!empty($user['photo_profil'])) {
        // Détecter le type MIME de l'image stockée en BLOB
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($user['photo_profil']);
        $mimeType = $mimeType ?: 'image/jpeg';
        $user['photo_profil'] = 'data:' . $mimeType . ';base64,' . base64_encode($user['photo_profil']);
    } else {
        $prenom = $user['prenom'] ?? 'J';
        $nom    = $user['nom']    ?? 'D';
        $user['photo_profil'] = 'https://ui-avatars.com/api/?name=' . urlencode($prenom . ' ' . $nom) . '&size=180&background=2c3e50&color=fff&bold=true';
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "user"    => $user
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>