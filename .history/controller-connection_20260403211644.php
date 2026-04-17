<?php
header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'vendor/autoload.php';
    require_once 'model.php';
    echo json_encode(['step' => 2, 'msg' => 'model ok']);
} catch (Exception $e) {
    echo json_encode(['step' => 2, 'error' => $e->getMessage()]);
}