<?php
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

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

function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

/* JWT */
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

/* DB */
try {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8','root','');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB', 500);
}

$decoded = validateAdmin();

/* GET */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    /* GET un seul employé par ID */
    if (isset($_GET['action']) && $_GET['action'] === 'get_employee') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) sendError("ID manquant");

        $stmt = $db->prepare("
            SELECT r.id, r.nom, r.prenom, r.email, r.role,
                   r.numero_telephone, r.date_naissance,
                   COALESCE(e.poste, r.poste, 'Non défini') AS poste
            FROM employes r
            LEFT JOIN emplois e ON r.id = e.employe_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            sendError("Employé non trouvé", 404);
        }

        sendJson(['success' => true, 'employee' => $employee]);
    }

    /* GET tous les employés */
    $stmt = $db->query("
        SELECT r.id, r.nom, r.prenom, r.email, r.role,
               r.numero_telephone,
               COALESCE(e.poste, r.poste, 'Non défini') AS poste
        FROM employes
        LEFT JOIN emplois e ON r.id = e.employe_id
        ORDER BY r.nom ASC
    ");

    sendJson($stmt->fetchAll(PDO::FETCH_ASSOC));
}
/* POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $raw = file_get_contents("php://input");
    $input = json_decode($raw, true);

    if ($raw && $input === null) {
        sendError("JSON invalide");
    }

    /* AJOUT */
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

    /* ✅ الجديد: role */
    $role = $input['role'] ?? 'employe';

    /* 🔒 حماية: فقط directeur ينجم يعيّن RH */
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

        sendJson([
            'success' => true,
            'id' => $id,
            'role' => $role
        ]);

    } catch (Exception $e) {

        $db->rollBack();

        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            sendError("Email existe déjà", 409);
        }

        sendError("Erreur serveur: " . $e->getMessage(), 500);
    }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendError("ID manquant");

    $stmt = $db->prepare("DELETE FROM e  WHERE id=?");
    $stmt->execute([$id]);

    sendJson(['success' => true]);
}

sendError("Méthode non autorisée", 405);