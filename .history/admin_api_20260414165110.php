<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

/* OPTIONS */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ================= HELPERS ================= */

function sendJson($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

/* ================= JWT ================= */

function validateAdmin(): object {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$authHeader) sendError('Token manquant', 401);

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $m)) {
        sendError('Token invalide', 401);
    }

    try {
        $decoded = JWT::decode($m[1], new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide', 401);
    }

    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['admin', 'administrateur', 'responsable_rh', 'directeur'])) {
        sendError('Accès refusé', 403);
    }

    return $decoded;
}

/* ================= DB ================= */

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root',
        ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB: ' . $e->getMessage(), 500);
}

validateAdmin();

/* ================= GET ================= */

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $action = $_GET['action'] ?? '';

    /* ---------- GET SINGLE EMPLOYEE ---------- */
    if ($action === 'get_employee') {

        $id = intval($_GET['id'] ?? 0);
        if (!$id) sendError("ID manquant");

        $stmt = $db->prepare("
            SELECT id, nom, prenom, email, role,
                   numero_telephone, date_naissance, poste,
                   photo_profil
            FROM employes
            WHERE id = ?
        ");
        $stmt->execute([$id]);

        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) sendError("Employé non trouvé", 404);

        // ✅ Ajouter URL complète de la photo
        if (!empty($employee['photo_profil'])) {
            $employee['photo_profil'] =
                "http://localhost/ton_projet/uploads/" . $employee['photo_profil'];
        } else {
            $employee['photo_profil'] = null;
        }

        sendJson([
            'success' => true,
            'employee' => $employee
        ]);
    }

    /* ---------- GET SALARY ---------- */
    elseif ($action === 'get_salary') {

        $employe_id = intval($_GET['employe_id'] ?? $_GET['employee_id'] ?? 0);
        if (!$employe_id) sendError("ID manquant");

        $stmt = $db->prepare("
            SELECT *
            FROM employee_salary
            WHERE employe_id = ?
            ORDER BY effective_date DESC
            LIMIT 1
        ");
        $stmt->execute([$employe_id]);
        $salary = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt2 = $db->prepare("
            SELECT *
            FROM employee_salary
            WHERE employe_id = ?
            ORDER BY effective_date DESC
        ");
        $stmt2->execute([$employe_id]);

        sendJson([
            'success' => true,
            'salary' => $salary ?: null,
            'history' => $stmt2->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    /* ---------- GET ALL EMPLOYEES ---------- */
    elseif ($action === 'get_all_employees' || $action === '') {

        $stmt = $db->query("
            SELECT id, nom, prenom, email, role,
                   numero_telephone, poste, statut, photo_profil
            FROM employes
            ORDER BY nom ASC
        ");

        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ✅ Ajouter URL pour كل الموظفين
        foreach ($employees as &$emp) {
            if (!empty($emp['photo_profil'])) {
                $emp['photo_profil'] =
                    "http://localhost/ton_projet/uploads/" . $emp['photo_profil'];
            } else {
                $emp['photo_profil'] = null;
            }
        }

        sendJson($employees);
    }

    else {
        sendError("Action inconnue: " . $action, 400);
    }
}

/* ================= POST ================= */

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input = json_decode(file_get_contents("php://input"), true);

    if ($input === null) sendError("JSON invalide");

    /* ---------- UPDATE SALARY ---------- */
    if (isset($input['action']) && $input['action'] === 'update_salary') {

        $employee_id = intval($input['employee_id'] ?? 0);
        $monthly_salary = floatval($input['monthly_salary'] ?? 0);
        $hourly_rate = floatval($input['hourly_rate'] ?? 0);
        $weekly_hours = floatval($input['weekly_hours'] ?? 0);
        $effective_date = $input['effective_date'] ?? '';
        $comment = $input['comment'] ?? '';

        if (!$employee_id || !$monthly_salary || !$hourly_rate || !$weekly_hours || !$effective_date) {
            sendError("Champs obligatoires manquants");
        }

        $stmt = $db->prepare("
            INSERT INTO employee_salary 
            (employe_id, monthly_salary, hourly_rate, weekly_hours, effective_date, comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $employee_id, $monthly_salary, $hourly_rate,
            $weekly_hours, $effective_date, $comment
        ]);

        sendJson([
            'success' => true,
            'message' => 'Salaire mis à jour',
            'id' => $db->lastInsertId()
        ]);
    }

    /* ---------- ADD EMPLOYEE ---------- */
    elseif (!isset($input['action'])) {

        $nom = trim($input['nom'] ?? '');
        $prenom = trim($input['prenom'] ?? '');
        $email = trim($input['email'] ?? '');
        $telephone = trim($input['numero_telephone'] ?? '');
        $date_naissance = trim($input['date_naissance'] ?? '');
        $poste = trim($input['poste'] ?? '');
        $password = $input['mot_de_passe'] ?? '';
        $rfid = trim($input['rfid_code'] ?? '') ?: null;

        if (!$nom || !$prenom || !$email || !$telephone || !$date_naissance || !$poste || !$password) {
            sendError("Champs obligatoires manquants");
        }

        $stmt = $db->prepare("SELECT id FROM employes WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) sendError("Email existe déjà", 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO employes
            (nom, prenom, email, numero_telephone, date_naissance, poste, mot_de_passe, rfid_code, role, statut, date_embauche)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, 'employe', 'actif', CURDATE())
        ");

        $stmt->execute([
            $nom, $prenom, $email, $telephone,
            $date_naissance, $poste, $hash, $rfid
        ]);

        sendJson([
            'success' => true,
            'message' => 'Employé ajouté',
            'id' => $db->lastInsertId()
        ]);
    }

    else {
        sendError("Action inconnue", 400);
    }
}

/* ================= DELETE ================= */

elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendError("ID manquant");

    $stmt = $db->prepare("DELETE FROM employes WHERE id = ?");
    $stmt->execute([$id]);

    sendJson(['success' => true, 'message' => 'Employé supprimé']);
}

/* ================= METHOD NOT ALLOWED ================= */

else {
    sendError("Méthode non autorisée", 405);
}
?>