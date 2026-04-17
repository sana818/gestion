<?php
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

// Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ================== FUNCTIONS ==================
function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

// ================== JWT ==================
function validateAdmin(): object {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$authHeader) sendError('Token manquant', 401);

    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) {
        sendError('Token invalide', 401);
    }

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide', 401);
    }

    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['responsable_rh', 'directeur', 'admin'])) {
        sendError('Accès refusé', 403);
    }

    return $decoded;
}

// ================== DB ==================
try {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8','root','');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB', 500);
}

// ================== VALIDATE JWT ==================
$decoded = validateAdmin();

// ================== GET ==================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // ---------- GET EMPLOYEE ----------
    if ($action === 'get_employee') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) sendError('ID manquant');

        $stmt = $db->prepare("
            SELECT r.id, r.nom, r.prenom, r.email, r.role, r.numero_telephone,
                   COALESCE(e.poste, 'Non défini') AS poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$emp) sendError('Employé non trouvé', 404);

        foreach (['date_naissance', 'photo_profil'] as $col) {
            try {
                $s = $db->prepare("SELECT `$col` FROM registre WHERE id = ?");
                $s->execute([$id]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                $emp[$col === 'photo_profil' ? 'photo' : $col] = $row[$col] ?? null;
            } catch (Exception $e) {
                $emp[$col === 'photo_profil' ? 'photo' : $col] = null;
            }
        }

        sendJson(['success' => true, 'employee' => $emp]);
    }

    // ---------- GET SALARY ----------
    if ($action === 'get_salary') {
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$employeeId) sendError('employee_id manquant');

        try {
            $stmt = $db->prepare("
                SELECT monthly_salary, hourly_rate, weekly_hours, effective_date, comment
                FROM salaires WHERE employe_id = ?
                ORDER BY effective_date DESC
            ");
            $stmt->execute([$employeeId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            sendJson(['success' => true, 'salary' => $history[0] ?? null, 'history' => $history]);
        } catch (Exception $e) {
            sendJson(['success' => false, 'salary' => null, 'history' => []]);
        }
    }

    // ---------- GET LISTE COMPLETE ----------
    $poste = $_GET['poste'] ?? '';
    if ($poste) {
        $stmt = $db->prepare("
            SELECT r.id, r.nom, r.prenom, r.email, r.role, r.numero_telephone,
                   COALESCE(e.poste, r.poste, 'Non défini') AS poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
            WHERE COALESCE(e.poste, r.poste) = ?
            ORDER BY r.nom ASC
        ");
        $stmt->execute([$poste]);
    } else {
        $stmt = $db->query("
            SELECT r.id, r.nom, r.prenom, r.email, r.role, r.numero_telephone,
                   COALESCE(e.poste, r.poste, 'Non défini') AS poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
            ORDER BY r.nom ASC
        ");
    }
    sendJson($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ================== POST ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents("php://input");
    $input = json_decode($raw, true);
    if ($raw && $input === null) sendError("JSON invalide");

    $required = ['nom','prenom','email','mot_de_passe','poste'];
    foreach ($required as $f) {
        if (empty($input[$f])) sendError("Champ $f requis");
    }

    $nom = trim($input['nom']);
    $prenom = trim($input['prenom']);
    $email = trim($input['email']);
    $poste = trim($input['poste']);
    $tel = $input['numero_telephone'] ?? '';
    $mdp = password_hash($input['mot_de_passe'], PASSWORD_BCRYPT);

    $role = $input['role'] ?? 'employe';
    // Seul directeur peut créer responsable_rh
    if ($role === 'responsable_rh' && strtolower($decoded->role) !== 'directeur') {
        $role = 'employe';
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO registre (nom, prenom, email, numero_telephone, mot_de_passe, role)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $prenom, $email, $tel, $mdp, $role]);
        $id = $db->lastInsertId();

        $stmt = $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)");
        $stmt->execute([$id, $poste]);

        $db->commit();

        sendJson(['success' => true, 'id' => $id, 'role' => $role]);
    } catch (Exception $e) {
        $db->rollBack();
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            sendError("Email existe déjà", 409);
        }
        sendError("Erreur serveur: " . $e->getMessage(), 500);
    }
}

// ================== DELETE ==================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendError("ID manquant");

    $stmt = $db->prepare("DELETE FROM registre WHERE id=?");
    $stmt->execute([$id]);

    sendJson(['success' => true]);
}

// ================== METHOD NOT ALLOWED ==================
sendError("Méthode non autorisée", 405);