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

if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant ou invalide"]);
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

    // Vérifier le rôle
    $stmt = $pdo->prepare("SELECT role FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !in_array(strtolower($user['role']), ['responsable_rh', 'directeur', 'admin'])) {
        ob_end_clean();
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Accès non autorisé"]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    $employeId    = intval($input['employe_id']   ?? 0);
    $salle        = trim($input['salle']           ?? '');
    $heureArrivee = trim($input['heure_arrivee']   ?? '');
    $heureSortie  = trim($input['heure_sortie']    ?? '');
    $joursTravail = json_encode($input['jours_travail'] ?? []);

    if (!$employeId || !$salle || !$heureArrivee || !$heureSortie) {
        ob_end_clean();
        echo json_encode(["success" => false, "message" => "Champs obligatoires manquants"]);
        exit;
    }

    // Vérifier si une ligne existe déjà pour cet employé
    $stmt = $pdo->prepare("SELECT id FROM presences WHERE employe_id = ? LIMIT 1");
    $stmt->execute([$employeId]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Mettre à jour salle + horaires
        $stmt = $pdo->prepare("
            UPDATE presences 
            SET salle                = ?,
                heure_arrivee_prevue = ?,
                heure_sortie_prevue  = ?,
                jours_travail        = ?
            WHERE employe_id = ?
        ");
        $stmt->execute([$salle, $heureArrivee, $heureSortie, $joursTravail, $employeId]);

    } else {
        // Créer une nouvelle ligne
        $stmt = $pdo->prepare("
            INSERT INTO presences 
                (employe_id, salle, heure_arrivee_prevue, heure_sortie_prevue, jours_travail, date, statut)
            VALUES 
                (?, ?, ?, ?, ?, CURDATE(), 'présent')
        ");
        $stmt->execute([$employeId, $salle, $heureArrivee, $heureSortie, $joursTravail]);
    }

    ob_end_clean();
    echo json_encode([
        "success" => true,
        "message" => "Salle et horaires enregistrés avec succès"
    ]);

} catch (PDOException $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur BD : " . $e->getMessage()]);
}
?>