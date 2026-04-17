<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Authentification (même code que profile1.php)
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (empty($authHeader)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Authorization header manquant"]);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token JWT manquant"]);
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

// Vérifier que l'utilisateur est responsable RH
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT role FROM registre WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || $user['role'] !== 'responsable_rh') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Accès non autorisé"]);
        exit;
    }
    
    // Récupérer les données POST
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(["success" => false, "message" => "Données manquantes"]);
        exit;
    }
    
    $employeId = $input['employe_id'];
    $salle = $input['salle'];
    $heureArrivee = $input['heure_arrivee'];
    $heureSortie = $input['heure_sortie'];
    $joursTravail = json_encode($input['jours_travail']);
    
    // Vérifier si un planning existe déjà
    $stmt = $pdo->prepare("SELECT id FROM horaires WHERE employe_id = ?");
    $stmt->execute([$employeId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Mise à jour
        $stmt = $pdo->prepare("
            UPDATE horaires 
            SET salle = ?, heure_arrivee = ?, heure_sortie = ?, jours_travail = ?
            WHERE employe_id = ?
        ");
        $stmt->execute([$salle, $heureArrivee, $heureSortie, $joursTravail, $employeId]);
        echo json_encode(["success" => true, "message" => "Horaires mis à jour"]);
    } else {
        // Insertion
        $stmt = $pdo->prepare("
            INSERT INTO horaires (employe_id, salle, heure_arrivee, heure_sortie, jours_travail)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$employeId, $salle, $heureArrivee, $heureSortie, $joursTravail]);
        echo json_encode(["success" => true, "message" => "Horaires créés"]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Erreur : " . $e->getMessage()]);
}
?>