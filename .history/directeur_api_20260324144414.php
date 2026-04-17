<?php
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ── Helpers ── */
function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

/* ── JWT ── */
function validateAdmin(): object {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token expiré ou invalide', 401);
    }

    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['responsable_rh', 'directeur', 'admin'])) {
        sendError('Accès refusé', 403);
    }

    return $decoded;
}

/* ── DB ── */
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB : ' . $e->getMessage(), 500);
}

$decoded = validateAdmin();

/* ════════════════════════════════════════
   GET — liste de tous les employés
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt = $db->query("
        SELECT
            r.id,
            r.nom,
            r.prenom,
            r.email,
            r.role,
            r.numero_telephone,
            COALESCE(e.poste, r.poste, 'Non défini') AS poste
        FROM registre r
        LEFT JOIN emplois e ON r.id = e.employe_id
        ORDER BY r.nom ASC
    ");

    sendJson($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* ════════════════════════════════════════
   POST — ajout, modification ou nomination RH
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if ($raw && $input === null) sendError('JSON invalide');

    /* ── Nomination / changement de rôle ── */
    if (isset($input['id']) && isset($input['role'])) {
        $id   = intval($input['id']);
        $role = trim($input['role']);

        $rolesAutorises = ['employe', 'responsable_rh'];
        if (!in_array($role, $rolesAutorises)) sendError('Rôle invalide');

        try {
            $stmt = $db->prepare("UPDATE registre SET role = ? WHERE id = ?");
            $stmt->execute([$role, $id]);
            sendJson(['success' => true, 'message' => "Rôle changé avec succès"]);
        } catch (Exception $e) {
            sendError('Erreur nomination : ' . $e->getMessage(), 500);
        }
    }

    /* ── Modification complète (id présent) ── */
    if (isset($input['id'])) {
        $id    = intval($input['id']);
        $nom   = trim($input['nom'] ?? '');
        $email = trim($input['email'] ?? '');

        if (!$nom || !$email) sendError('Champs nom et email requis');

        try {
            $stmt = $db->prepare("UPDATE registre SET nom = ?, email = ? WHERE id = ?");
            $stmt->execute([$nom, $email, $id]);
            sendJson(['success' => true, 'message' => 'Employé modifié avec succès']);
        } catch (Exception $e) {
            sendError('Erreur modification : ' . $e->getMessage(), 500);
        }
    }

    /* ── Ajout d’un nouvel employé ── */
    $required = ['nom', 'prenom', 'email', 'mot_de_passe', 'poste'];
    foreach ($required as $f) {
        if (empty($input[$f])) sendError("Champ '$f' requis");
    }

    $nom    = trim($input['nom']);
    $prenom = trim($input['prenom']);
    $email  = trim($input['email']);
    $poste  = trim($input['poste']);
    $tel    = trim($input['numero_telephone'] ?? '');
    $mdp    = password_hash($input['mot_de_passe'], PASSWORD_BCRYPT);
    $role   = in_array($input['role'] ?? '', ['employe', 'responsable_rh']) ? $input['role'] : 'employe';

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO registre (nom, prenom, email, numero_telephone, mot_de_passe, role)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $prenom, $email, $tel, $mdp, $role]);

        $id = $db->lastInsertId();

        $stmt = $db->prepare("
            INSERT INTO emplois (employe_id, poste)
            VALUES (?, ?)
        ");
        $stmt->execute([$id, $poste]);

        $db->commit();
        sendJson(['success' => true, 'id' => (int)$id, 'role' => $role]);

    } catch (Exception $e) {
        $db->rollBack();
        if (strpos($e->getMessage(), 'Duplicate') !== false) sendError('Email déjà utilisé', 409);
        sendError('Erreur serveur : ' . $e->getMessage(), 500);
    }
}

/* ════════════════════════════════════════
   DELETE — suppression
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendError('ID manquant');

    try {
        $stmt = $db->prepare("DELETE FROM registre WHERE id = ?");
        $stmt->execute([$id]);
        sendJson(['success' => true]);
    } catch (Exception $e) {
        sendError('Erreur suppression : ' . $e->getMessage(), 500);
    }
}

sendError('Méthode non autorisée', 405);