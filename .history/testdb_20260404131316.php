<?php
header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(['test' => 1]);
    
    require_once __DIR__ . '/vendor/autoload.php';
    
    echo json_encode(['test' => 2, 'message' => 'JWT OK']);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>