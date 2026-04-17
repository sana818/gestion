<?php
header('Content-Type: application/json; charset=utf-8');

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    echo json_encode(['step' => 1, 'message' => 'Début']);
    echo json_encode(['step' => 2, 'message' => 'Imports OK']);
    echo json_encode(['step' => 3, 'message' => 'Use OK']);

    // Test JWT
    $headers = getallheaders();
    echo json_encode(['step' => 4, 'message' => 'Headers OK', 'headers_keys' => array_keys($headers)]);

    $jwt = $headers['X-Token'] ?? $headers['Authorization'] ?? null;
    echo json_encode(['step' => 5, 'message' => 'JWT found', 'has_jwt' => !is_null($jwt)]);

    if ($jwt && strpos($jwt, 'Bearer ') === 0) {
        $jwt = substr($jwt, 7);
    }

    echo json_encode(['step' => 6, 'message' => 'JWT extracted']);

    if (!$jwt) {
        echo json_encode(['step' => 7, 'error' => 'JWT manquant']);
        exit;
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    try {
        $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
        echo json_encode(['step' => 8, 'message' => 'JWT decoded', 'user_id' => $decoded->id]);
    } catch (Exception $e) {
        echo json_encode(['step' => 8, 'error' => 'JWT decode failed: ' . $e->getMessage()]);
        exit;
    }

    $userId = $decoded->id ?? null;
    echo json_encode(['step' => 9, 'message' => 'User ID', 'userId' => $userId]);

    if (!$userId) {
        echo json_encode(['step' => 10, 'error' => 'User ID null']);
        exit;
    }

    // Test POST data
    echo json_encode(['step' => 11, 'message' => 'POST data', 'post_keys' => array_keys($_POST)]);

    $date = $_POST['date'] ?? null;
    $heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? null;
    $heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
    $duree_retard = $_POST['duree_retard'] ?? null;
    $raison = $_POST['raison'] ?? null;

    echo json_encode(['step' => 12, 'POST' => [
        'date' => $date,
        'heure_arrivee_reelle' => $heure_arrivee_reelle,
        'heure_arrivee_prevue' => $heure_arrivee_prevue,
        'duree_retard' => $duree_retard,
        'raison' => $raison
    ]]);

    // Test Database
    $pdo = Database::connect();
    echo json_encode(['step' => 13, 'message' => 'Database connected']);

    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['step' => 14, 'message' => 'User found', 'user' => $user]);

    echo json_encode(['step' => 15, 'success' => true, 'message' => 'Tous les tests OK']);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
?>