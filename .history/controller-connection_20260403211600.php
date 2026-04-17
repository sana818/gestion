<?php
header('Content-Type: application/json; charset=utf-8');

try {
    require_once 'vendor/autoload.php';
    require_once 'model.php';
    
    $user = User::findByEmail('azizbenamara@gmail.com'); // ← mets ton vrai email ici
    echo json_encode(['step' => 3, 'user_found' => $user ? true : false]);
} catch (Exception $e) {
    echo json_encode(['step' => 3, 'error' => $e->getMessage()]);
}