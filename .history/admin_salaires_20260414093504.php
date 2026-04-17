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

/* ══ GET ══ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->query("
        SELECT 
            e.id,
            e.nom,
            e.prenom,
            e.poste,
            COALESCE(s.monthly_salary, 0)  AS monthly_salary,
            COALESCE(s.hourly_rate, 0)     AS hourly_rate,
            COALESCE(s.weekly_hours, 0)    AS weekly_hours,
            s.effective_date,
            s.comment,
            s.created_at
        FROM employes e
        LEFT JOIN salaires s ON s.employee_id = e.id
        WHERE e.role = 'employe'
        ORDER BY e.nom ASC
    ");
    sendJson(['success' => true, 'salaires' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);

    $action         = $input['action'] ?? 'save';
    $employee_id    = intval($input['employee_id'] ?? 0);
    $monthly_salary = floatval($input['monthly_salary'] ?? 0);
    $hourly_rate    = floatval($input['hourly_rate'] ?? 0);
    $weekly_hours   = floatval($input['weekly_hours'] ?? 40);
    $effective_date = trim($input['effective_date'] ?? date('Y-m-d'));
    $comment        = trim($input['comment'] ?? '');

    if (!$employee_id) sendError("ID employé manquant");

    // Vérifier que l'employé existe
    $stmt = $db->prepare("SELECT id FROM employes WHERE id = ?");
    $stmt->execute([$employee_id]);
    if (!$stmt->fetch()) sendError("Employé introuvable", 404);

    // Vérifier si un salaire existe déjà pour cet employé
    $stmt = $db->prepare("SELECT id FROM salaires WHERE employee_id = ?");
    $stmt->execute([$employee_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        // UPDATE
        $stmt = $db->prepare("
            UPDATE salaires 
            SET monthly_salary  = ?,
                hourly_rate     = ?,
                weekly_hours    = ?,
                effective_date  = ?,
                comment         = ?
            WHERE employee_id = ?
        ");
        $stmt->execute([$monthly_salary, $hourly_rate, $weekly_hours, $effective_date, $comment, $employee_id]);
    } else {
        // INSERT
        $stmt = $db->prepare("
            INSERT INTO salaires (employee_id, monthly_salary, hourly_rate, weekly_hours, effective_date, comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$employee_id, $monthly_salary, $hourly_rate, $weekly_hours, $effective_date, $comment]);
    }

    sendJson(['success' => true, 'message' => 'Salaire enregistré avec succès']);
}

sendError("Méthode non autorisée", 405);