<?php
header('Content-Type: application/json; charset=utf-8');

require_once 'Database.php';

try {
    $pdo = Database::connect();
    echo json_encode(['success' => true, 'message' => 'DB connectée']);

    $stmt = $pdo->query("SELECT id, nom, prenom FROM employes LIMIT 3");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['users' => $users]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>