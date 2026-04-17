<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ===== AUTH =====
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant"]);
    exit;
}

$jwt = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide"]);
    exit;
}

$userId = $decoded->id;

// ===== DB =====
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            e.date_embauche,
            h.salle,
            h.heure_arrivee,
            h.heure_sortie,
            h.jours_travail
        FROM registre r
        LEFT JOIN emplois e ON r.id = e.employe_id
        LEFT JOIN horaires h ON r.id = h.employe_id
        WHERE r.id = ?
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur non trouvé"]);
        exit;
    }

    // Gestion de la photo de profil
    if (!empty($user['photo_profil'])) {
        $user['photo_profil'] = 'data:;base64,' . base64_encode($user['photo_profil']);
    } else {
        $prenom = !empty($user['prenom']) ? $user['prenom'] : 'Jean';
        $nom = !empty($user['nom']) ? $user['nom'] : 'Dupont';
        $user['photo_profil'] = 'https://ui-avatars.com/api/?name=' . urlencode($prenom . ' ' . $nom) . '&size=180&background=2c3e50&color=fff&bold=true';
    }

    echo json_encode([
        "success" => true,
        "user" => $user
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>