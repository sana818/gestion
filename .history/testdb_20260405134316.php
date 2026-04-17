<?php
require_once 'Database.php';

$email = 'ons@gmail.com';

$stmt = $conn->prepare("SELECT email, mot_de_passe, statut FROM employes WHERE email = :email");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Utilisateur trouvé : " . ($user ? "OUI" : "NON") . "\n";

if ($user) {
    echo "Email : " . $user['email'] . "\n";
    echo "Statut : " . $user['statut'] . "\n";
    echo "Hash : " . $user['mot_de_passe'] . "\n";
    
    $mot_de_passe_test = 'METTEZ_VOTRE_MOT_DE_PASSE_ICI'; // ← changez ici
    $resultat = password_verify($mot_de_passe_test, $user['mot_de_passe']);
    echo "password_verify : " . ($resultat ? "OK ✅" : "ECHEC ❌") . "\n";
}
?>