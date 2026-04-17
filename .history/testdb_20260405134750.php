<?php
require_once 'Database.php';

$nouveau_hash = password_hash('onsonsons', PASSWORD_DEFAULT); // ← mettez le vrai mot de passe

$stmt = $conn->prepare("UPDATE employes SET mot_de_passe = ? WHERE email = ?");
$stmt->execute([$nouveau_hash, 'ons@gmail.com']);

echo "Hash complet : " . $nouveau_hash . "<br>";
echo "Longueur : " . strlen($nouveau_hash) . " caractères<br>";
echo "Mise à jour effectuée ✅";
?>