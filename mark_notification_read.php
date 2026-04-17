<?php
require_once 'config.php';

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$response = ['success' => false, 'error' => ''];

try {
    // Récupérer le token
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!$authHeader || !preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
        throw new Exception('Token manquant', 401);
    }

    $jwt = $matches[1];
    $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    $userId = $decoded->id;

    // Récupérer l'ID de la notification depuis la requête
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = $input['notification_id'] ?? null;

    if (!$notificationId) {
        throw new Exception('ID de notification manquant');
    }

    // Marquer comme lue
    $stmt = $pdo->prepare("UPDATE notifications SET lu = 1 WHERE id = ? AND destinataire_id = ?");
    $stmt->execute([$notificationId, $userId]);

    $response['success'] = true;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>