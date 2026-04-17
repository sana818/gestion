<?php
header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'vendor/autoload.php';
    echo json_encode(['step' => 1, 'msg' => 'autoload ok']);
} catch (Exception $e) {
    echo json_encode(['step' => 1, 'error' => $e->getMessage()]);
}