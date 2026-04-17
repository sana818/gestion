<?php
require_once 'Database.php';

try {
    $db = new PDO("mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8", "root", "");
    echo json_encode(["success" => true, "message" => "Connexion DB OK"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}