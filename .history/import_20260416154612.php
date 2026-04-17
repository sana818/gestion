<?php
$host = "nozomi.proxy.rlwy.net";
$user = "root";
$pass = "oCUbKWxqzhtnsTetbuqlkAupcKuqogNu";
$db   = "railway";
$port = 19196;

$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    die("Connexion échouée: " . $conn->connect_error);
}

$sql = file_get_contents("db.sql");

if ($conn->multi_query($sql)) {
    echo "Import réussi!";
} else {
    echo "Erreur import: " . $conn->error;
}
?>