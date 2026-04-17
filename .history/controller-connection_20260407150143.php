<?php
header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "gestion_utilisateurs");

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Erreur connexion DB"]);
    exit;
}

$email = $_POST['email'];
$password = $_POST['password'];

$sql = "SELECT * FROM employes WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo json_encode(["error" => "Utilisateur non trouvé"]);
    exit;
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['mot_de_passe'])) {
    http_response_code(401);
    echo json_encode(["error" => "Mot de passe incorrect"]);
    exit;
}

/* ❗ هنا أهم تعديل */
if ($user['statut'] !== 'actif') {
    http_response_code(403);
    echo json_encode([
        "error" => "Votre compte est en attente de validation ou désactivé."
    ]);
    exit;
}

/* login OK */
echo json_encode([
    "success" => true,
    "user" => $user
]);
?>