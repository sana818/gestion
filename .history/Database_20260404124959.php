<?php
$host     = 'localhost';
$dbname   = 'gestion_utilisateurs';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    error_log('DB Connection Error: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode([
        'success' => false, 
        'error' => 'Connexion DB échouée',
        'debug' => $_ENV['APP_DEBUG'] === 'true' ? $e->getMessage() : null
    ]));
}
?>