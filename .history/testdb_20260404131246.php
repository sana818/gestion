<?php
header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(['test' => 1, 'message' => 'Avant Database']);
    
    require_once 'Database.php';
    
    echo json_encode(['test' => 2, 'message' => 'Après Database']);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>