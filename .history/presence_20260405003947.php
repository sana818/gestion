<?php
ob_start();
ini_set('display_errors', 0);
header('Content-Type: application/json');

// Connexion à la base de données
$host = 'localhost';
$db   = 'gestion_utilisateurs';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erreur connexion DB: '.$e->getMessage()]);
    exit;
}

// Vérifier l'action
$action = $_GET['action'] ?? '';

if($action === 'get_presence') {
    try {
        $stmt = $pdo->query("
            SELECT id, employe, date, heure_arrivee, heure_depart, statut
            FROM presence
            ORDER BY date DESC, heure_arrivee ASC
        ");
        $presences = $stmt->fetchAll();
        echo json_encode(['success' => true, 'presences' => $presences]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Erreur SQL: '.$e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Action invalide']);
}
?>
