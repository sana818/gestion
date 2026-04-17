<?php
/**
 * directeur_api.php
 * Actions réservées au Directeur : nommer / révoquer un Responsable RH
 */

require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ── helpers ── */
function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}
function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

/* ── JWT : réservé au directeur ── */
function validateDirecteur(): object {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
                ?? $headers['authorization']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token JWT manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Format token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide ou expiré : ' . $e->getMessage(), 401);
    }

    if (!isset($decoded->role) || strtolower($decoded->role) !== 'directeur') {
        sendError('Accès réservé au Directeur', 403);
    }

    return $decoded;
}

/* ── PDO ── */
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur BDD : ' . $e->getMessage(), 500);
}

/* ── uniquement POST ── */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Méthode non autorisée', 405);
}

$decoded = validateDirecteur();
$directeurId = $decoded->id ?? $decoded->user_id ?? null;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) sendError('Corps JSON invalide', 400);

$action     = $input['action']      ?? '';
$employeId  = intval($input['employe_id'] ?? 0);

if (!$employeId) sendError('employe_id manquant ou invalide', 422);

/* ── vérifier que l'employé existe ── */
$stmtEmp = $db->prepare("SELECT id, nom, prenom, role FROM registre WHERE id = ?");
$stmtEmp->execute([$employeId]);
$employe = $stmtEmp->fetch(PDO::FETCH_ASSOC);

if (!$employe) sendError('Employé introuvable', 404);

/* ════════════════════════════════════════
   ACTION : nommer_rh
   ════════════════════════════════════════ */
if ($action === 'nommer_rh') {

    if (strtolower($employe['role']) === 'rh') {
        sendError('Cet employé est déjà Responsable RH', 409);
    }
    if (strtolower($employe['role']) === 'directeur') {
        sendError('Impossible de modifier le rôle d\'un directeur', 403);
    }

    $db->beginTransaction();
    try {
        /* 1. Mettre à jour le rôle */
        $stmt = $db->prepare("
            UPDATE registre
            SET role = 'responsable_rh'
            WHERE id = ?
        ");
        $stmt->execute([$employeId]);

        /* 2. Journaliser */
        $stmt = $db->prepare("
            INSERT INTO historique_nominations
                (directeur_id, employe_id, action, created_at)
            VALUES (?, ?, 'nomination', NOW())
        ");
        $stmt->execute([$directeurId, $employeId]);

        /* 3. Notifier l'employé */
        $nom    = trim(($employe['prenom'] ?? '') . ' ' . ($employe['nom'] ?? ''));
        $msgEmp = "Félicitations ! Vous avez été nommé Responsable RH par la direction.";
        $stmt   = $db->prepare("
            INSERT INTO notifications (destinataire_id, type, message, date, lu)
            VALUES (?, 'info', ?, NOW(), 0)
        ");
        $stmt->execute([$employeId, $msgEmp]);

        $db->commit();

        sendJson([
            'success' => true,
            'message' => "$nom a été nommé Responsable RH.",
            'employe' => [
                'id'   => $employeId,
                'nom'  => $nom,
                'role' => 'responsable_rh'
            ]
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        sendError('Erreur lors de la nomination : ' . $e->getMessage(), 500);
    }
}

/* ════════════════════════════════════════
   ACTION : revoquer_rh
   ════════════════════════════════════════ */
elseif ($action === 'revoquer_rh') {

    if (strtolower($employe['role']) !== 'responsable_rh') {
        sendError('Cet employé n\'est pas Responsable RH', 409);
    }

    $db->beginTransaction();
    try {
        /* 1. Repasser en employé standard */
        $stmt = $db->prepare("
            UPDATE registre
            SET role = 'employe'
            WHERE id = ?
        ");
        $stmt->execute([$employeId]);

        /* 2. Journaliser */
        $stmt = $db->prepare("
            INSERT INTO historique_nominations
                (directeur_id, employe_id, action, created_at)
            VALUES (?, ?, 'revocation', NOW())
        ");
        $stmt->execute([$directeurId, $employeId]);

        /* 3. Notifier l'employé */
        $nom    = trim(($employe['prenom'] ?? '') . ' ' . ($employe['nom'] ?? ''));
        $msgEmp = "Votre rôle de Responsable RH a été révoqué. Vous êtes maintenant Employé standard.";
        $stmt   = $db->prepare("
            INSERT INTO notifications (destinataire_id, type, message, date, lu)
            VALUES (?, 'info', ?, NOW(), 0)
        ");
        $stmt->execute([$employeId, $msgEmp]);

        $db->commit();

        sendJson([
            'success' => true,
            'message' => "$nom a été révoqué du poste de Responsable RH.",
            'employe' => [
                'id'   => $employeId,
                'nom'  => $nom,
                'role' => 'employe'
            ]
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        sendError('Erreur lors de la révocation : ' . $e->getMessage(), 500);
    }
}

else {
    sendError("Action '$action' non reconnue. Actions valides : nommer_rh, revoquer_rh", 400);
}