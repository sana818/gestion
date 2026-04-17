<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers    = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($authHeader)) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant"]);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide"]);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($matches[1], new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide"]);
    exit;
}

$userId = $decoded->id;

try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ✅ employes au lieu de registre
    $stmt = $pdo->prepare("SELECT role FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $rolesPermis = ['responsable_rh', 'directeur', 'admin'];
    if (!$user || !in_array(strtolower($user['role']), $rolesPermis)) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Accès non autorisé"]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => "Données manquantes"]);
        exit;
    }

    $employeId    = $input['employe_id'];
    $salle        = $input['salle'];
    $heureArrivee = $input['heure_arrivee'];
    $heureSortie  = $input['heure_sortie'];
    $joursTravail = json_encode($input['jours_travail']);

    // ✅ presences au lieu de horaires
    // Vérifier si un enregistrement existe déjà pour cet employé
    $stmt = $pdo->prepare("SELECT id FROM presences WHERE employe_id = ? LIMIT 1");
    $stmt->execute([$employeId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE presences 
            SET heure_arrivee_prevue = ?, heure_sortie_prevue = ?, jours_travail = ?
            WHERE employe_id = ?
        ");
        $stmt->execute([$heureArrivee, $heureSortie, $joursTravail, $employeId]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO presences (employe_id, heure_arrivee_prevue, heure_sortie_prevue, jours_travail, date, statut)
            VALUES (?, ?, ?, ?, CURDATE(), 'présent')
        ");
        $stmt->execute([$employeId, $heureArrivee, $heureSortie, $joursTravail]);
    }

    ob_end_clean();
    echo json_encode(["success" => true, "message" => "Horaires enregistrés avec succès"]);

} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur : " . $e->getMessage()]);
}
?>