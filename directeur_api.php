<?php
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

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

function validateDirecteur(): object {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
                ?? $headers['authorization']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token expiré ou invalide', 401);
    }

    // ✅ Accepte 'directeur' ET 'administrateur'
    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['directeur', 'administrateur'])) {
        sendError('Accès réservé au administrateur', 403);
    }

    return $decoded;
}

try {
    $db = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB : ' . $e->getMessage(), 500);
}

$decoded = validateDirecteur();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Méthode non autorisée', 405);
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!$input || !isset($input['action'])) {
    sendError('Action manquante');
}

$action     = $input['action'];
$employe_id = intval($input['employe_id'] ?? 0);

if (!$employe_id) {
    sendError('employe_id manquant ou invalide');
}

// ✅ Table employes
$stmt = $db->prepare("SELECT id, nom, role FROM employes WHERE id = ?");
$stmt->execute([$employe_id]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    sendError('Employé introuvable', 404);
}

// ════════════════════════
// ACTION : nommer_rh
// ════════════════════════
if ($action === 'nommer_rh') {
    if ($emp['role'] === 'responsable_rh') {
        sendError('Cet employé est déjà Responsable RH');
    }
    if (in_array($emp['role'], ['directeur', 'administrateur'])) {
        sendError('Impossible de modifier le rôle d\'un directeur');
    }

    try {
        $stmt = $db->prepare("UPDATE employes SET role = 'responsable_rh' WHERE id = ?");
        $stmt->execute([$employe_id]);
        sendJson([
            'success' => true,
            'message' => $emp['nom'] . ' nommé Responsable RH avec succès'
        ]);
    } catch (Exception $e) {
        sendError('Erreur serveur : ' . $e->getMessage(), 500);
    }
}

// ════════════════════════
// ACTION : revoquer_rh
// ════════════════════════
if ($action === 'revoquer_rh') {
    if ($emp['role'] !== 'responsable_rh') {
        sendError('Cet employé n\'est pas Responsable RH');
    }

    try {
        $stmt = $db->prepare("UPDATE employes SET role = 'employe' WHERE id = ?");
        $stmt->execute([$employe_id]);
        sendJson([
            'success' => true,
            'message' => $emp['nom'] . ' révoqué avec succès'
        ]);
    } catch (Exception $e) {
        sendError('Erreur serveur : ' . $e->getMessage(), 500);
    }
}

sendError('Action inconnue : ' . htmlspecialchars($action));