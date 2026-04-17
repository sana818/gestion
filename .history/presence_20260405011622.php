<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');

$host    = 'localhost';
$db      = 'gestion_utilisateurs';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn     = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Erreur connexion DB: ' . $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'get_presence') {
    try {
        $date = $_GET['date'] ?? null;

        if ($date) {
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    CONCAT(e.prenom, ' ', e.nom) AS employe,
                    p.date,
                    p.heure_arrivee,
                    p.heure_depart,
                    p.statut
                FROM presences p
                LEFT JOIN employes e ON p.employe_id = e.id
                WHERE p.date = ?
                ORDER BY p.date DESC, p.heure_arrivee ASC
            ");
            $stmt->execute([$date]);
        } else {
            $stmt = $pdo->query("
                SELECT 
                    p.id,
                    CONCAT(e.prenom, ' ', e.nom) AS employe,
                    p.date,
                    p.heure_arrivee,
                    p.heure_depart,
                    p.statut
                FROM presences p
                LEFT JOIN employes e ON p.employe_id = e.id
                ORDER BY p.date DESC, p.heure_arrivee ASC
            ");
        }

        $presences = $stmt->fetchAll();
        ob_end_clean();
        echo json_encode(['success' => true, 'presences' => $presences]);

    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Erreur SQL: ' . $e->getMessage()]);
    }

} else {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Action invalide']);
}
?>