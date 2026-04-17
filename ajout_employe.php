<?php
header('Content-Type: application/json');
require_once 'Database.php'; // connexion à la base

$input = json_decode(file_get_contents('php://input'), true);

$required = ['nom', 'prenom', 'date_naissance', 'email', 'numero_telephone', 'mot_de_passe', 'poste'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        echo json_encode(['success' => false, 'message' => "Le champ $field est requis"]);
        exit;
    }
}

// Hash du mot de passe
$hashPassword = password_hash($input['mot_de_passe'], PASSWORD_DEFAULT);

try {
    $stmt = $conn->prepare("
        INSERT INTO registre (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, role, poste)
        VALUES (:nom, :prenom, :date_naissance, :email, :numero_telephone, :mot_de_passe, 'employe', :poste)
    ");
    $stmt->execute([
        ':nom' => $input['nom'],
        ':prenom' => $input['prenom'],
        ':date_naissance' => $input['date_naissance'],
        ':email' => $input['email'],
        ':numero_telephone' => $input['numero_telephone'],
        ':mot_de_passe' => $hashPassword,
        ':poste' => $input['poste']
    ]);

    echo json_encode(['success' => true, 'message' => "Employé ajouté avec succès"]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
