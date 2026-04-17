<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendJson($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

function validateAdmin(): object {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] 
                  ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$authHeader) sendError('Token manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide', 401);
    }

    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['responsable_rh', 'directeur', 'administrateur', 'admin'])) {
        sendError('Accès refusé', 403);
    }

    return $decoded;
}

try {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB', 500);
}

$decoded = validateAdmin();

// ══ Créer la table si elle n'existe pas ══
$db->exec("
    CREATE TABLE IF NOT EXISTS salaires (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employe_id INT NOT NULL,
        salaire_base DECIMAL(10,2) DEFAULT 0,
        primes DECIMAL(10,2) DEFAULT 0,
        deductions DECIMAL(10,2) DEFAULT 0,
        date_effet DATE DEFAULT (CURDATE()),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_employe (employe_id),
        FOREIGN KEY (employe_id) REFERENCES employes(id) ON DELETE CASCADE
    )
");

/* ══ GET ══ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT e.id, e.nom, e.prenom, e.poste,
               COALESCE(s.salaire_base, 0) as salaire_base,
               COALESCE(s.primes, 0) as primes,
               COALESCE(s.deductions, 0) as deductions,
               s.date_effet, s.updated_at
        FROM employes e
        LEFT JOIN salaires s ON s.employe_id = e.id
        WHERE e.role = 'employe'
        ORDER BY e.nom ASC
    ");
    sendJson(['success' => true, 'salaires' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);

    $employe_id   = intval($input['employe_id'] ?? 0);
    $salaire_base = floatval($input['salaire_base'] ?? 0);
    $primes       = floatval($input['primes'] ?? 0);
    $deductions   = floatval($input['deductions'] ?? 0);
    $date_effet   = trim($input['date_effet'] ?? date('Y-m-d'));

    if (!$employe_id) sendError("ID employé manquant");
    if ($salaire_base < 0) sendError("Salaire invalide");

    // Vérifier que l'employé existe
    $stmt = $db->prepare("SELECT id FROM employes WHERE id = ?");
    $stmt->execute([$employe_id]);
    if (!$stmt->fetch()) sendError("Employé introuvable", 404);

    // INSERT ou UPDATE (UPSERT)
    $stmt = $db->prepare("
        INSERT INTO salaires (employe_id, salaire_base, primes, deductions, date_effet)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            salaire_base = VALUES(salaire_base),
            primes       = VALUES(primes),
            deductions   = VALUES(deductions),
            date_effet   = VALUES(date_effet)
    ");
    $stmt->execute([$employe_id, $salaire_base, $primes, $deductions, $date_effet]);

    sendJson(['success' => true, 'message' => 'Salaire enregistré avec succès']);
}

sendError("Méthode non autorisée", 405);