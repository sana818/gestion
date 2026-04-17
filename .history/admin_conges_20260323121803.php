<?php
/**
 * admin_conges.php
 * Consultation et traitement des congés
 * Rôles autorisés : responsable_rh, directeur
 */

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

function sendJson($data, $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}
function sendError($msg, $code = 400): void {
    sendJson(['success' => false, 'error' => $msg], $code);
}

/* ── JWT ── */
function validateToken(): object {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
                ?? $headers['authorization']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token JWT manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Format token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide : ' . $e->getMessage(), 401);
    }

    $role    = strtolower($decoded->role ?? '');
    $allowed = ['responsable_rh', 'directeur', 'admin', 'employe'];

    if (!in_array($role, $allowed)) sendError('Accès refusé', 403);

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

$decoded     = validateToken();
$role        = strtolower($decoded->role ?? '');
$currentId   = $decoded->id ?? $decoded->user_id ?? null;
$isAdmin     = in_array($role, ['responsable_rh', 'directeur', 'admin']);
$isEmploye   = $role === 'employe';

/* ══════════════════════════════════════
   GET
   ══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $action = $_GET['action'] ?? '';

    if ($action === 'get_conges' && $isAdmin) {

        $stmt = $db->prepare("
            SELECT
                c.id,
                c.employe_id,
                r.nom,
                r.prenom,
                CONCAT(r.prenom, ' ', r.nom) AS employe,
                c.type_conge         AS type,
                c.date_debut,
                c.date_fin,
                c.jours_demande      AS nb_jours,
                c.statut,
                c.commentaire        AS commentaire_admin,
                c.certificat_medical,
                c.date_demande       AS created_at
            FROM conges c
            JOIN registre r ON c.employe_id = r.id
            ORDER BY
                CASE WHEN c.statut = 'en_attente' THEN 0 ELSE 1 END,
                c.date_demande DESC
        ");
        $stmt->execute();
        $conges = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendJson(['success' => true, 'data' => $conges]);
    }

    /* employé : ses propres congés */
    if ($action === 'mes_conges' && $isEmploye) {
        $stmt = $db->prepare("
            SELECT id, type_conge AS type, date_debut, date_fin,
                   jours_demande AS nb_jours, statut, commentaire,
                   date_demande AS created_at
            FROM conges
            WHERE employe_id = ?
            ORDER BY date_demande DESC
        ");
        $stmt->execute([$currentId]);
        sendJson(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    sendError('Action non reconnue ou permissions insuffisantes', 400);
}

/* ══════════════════════════════════════
   POST
   ══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $input  = json_decode(file_get_contents('php://input'), true);
    if (!$input) sendError('Corps JSON invalide', 400);

    $action = $input['action'] ?? '';

    /* ── traiter (admin/directeur) ── */
    if ($action === 'traiter_conge' && $isAdmin) {

        $demandeId   = intval($input['demande_id']  ?? 0);
        $statut      = $input['statut']             ?? '';
        $commentaire = trim($input['commentaire']   ?? '');

        if (!$demandeId) sendError('demande_id manquant', 422);
        if (!in_array($statut, ['accepte', 'refuse'])) sendError('Statut invalide (accepte|refuse)', 422);

        /* récupérer la demande */
        $stmt = $db->prepare("
            SELECT c.employe_id, c.date_debut, c.date_fin,
                   r.nom, r.prenom
            FROM conges c
            JOIN registre r ON c.employe_id = r.id
            WHERE c.id = ?
        ");
        $stmt->execute([$demandeId]);
        $demande = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$demande) sendError('Demande introuvable', 404);

        $db->beginTransaction();
        try {
            /* mettre à jour le statut */
            $stmt = $db->prepare("
                UPDATE conges SET statut = ?, commentaire = ? WHERE id = ?
            ");
            $stmt->execute([$statut, $commentaire, $demandeId]);

            /* notifier l'employé */
            $dd  = date('d/m/Y', strtotime($demande['date_debut']));
            $df  = date('d/m/Y', strtotime($demande['date_fin']));
            $lib = $statut === 'accepte' ? 'acceptée ✅' : 'refusée ❌';
            $msg = "Votre demande de congé du $dd au $df a été $lib.";
            if ($commentaire) $msg .= " Commentaire : $commentaire";

            $stmt = $db->prepare("
                INSERT INTO notifications (destinataire_id, type, message, date, lu)
                VALUES (?, 'conge', ?, NOW(), 0)
            ");
            $stmt->execute([$demande['employe_id'], $msg]);

            $db->commit();
            sendJson(['success' => true, 'message' => 'Demande traitée']);

        } catch (Exception $e) {
            $db->rollBack();
            sendError('Erreur traitement : ' . $e->getMessage(), 500);
        }
    }

    /* ── nouvelle demande (employé) ── */
    if ($action === 'nouvelle_demande' && $isEmploye) {

        $employe_id    = $currentId;
        $type_conge    = trim($input['type_conge']    ?? '');
        $date_debut    = $input['date_debut']          ?? '';
        $date_fin      = $input['date_fin']            ?? '';
        $jours         = intval($input['jours_demande'] ?? 0);
        $commentaire   = trim($input['commentaire']    ?? '');

        if (!$type_conge || !$date_debut || !$date_fin) sendError('Champs obligatoires manquants', 422);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO conges
                    (employe_id, type_conge, date_debut, date_fin, jours_demande, commentaire, statut, date_demande)
                VALUES (?, ?, ?, ?, ?, ?, 'en_attente', NOW())
            ");
            $stmt->execute([$employe_id, $type_conge, $date_debut, $date_fin, $jours, $commentaire]);
            $newId = $db->lastInsertId();

            /* récupérer le nom de l'employé */
            $stmt = $db->prepare("SELECT nom, prenom FROM registre WHERE id = ?");
            $stmt->execute([$employe_id]);
            $emp     = $stmt->fetch(PDO::FETCH_ASSOC);
            $empNom  = trim(($emp['prenom'] ?? '') . ' ' . ($emp['nom'] ?? ''));

            /* notifier les RH et directeurs */
            $dd  = date('d/m/Y', strtotime($date_debut));
            $df  = date('d/m/Y', strtotime($date_fin));
            $msg = "📅 Nouvelle demande de congé de $empNom du $dd au $df ($type_conge).";

            $admins = $db->query("
                SELECT id FROM registre
                WHERE role IN ('responsable_rh', 'directeur', 'admin')
            ")->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $db->prepare("
                INSERT INTO notifications (destinataire_id, type, message, date, lu)
                VALUES (?, 'conge', ?, NOW(), 0)
            ");
            foreach ($admins as $adminId) {
                $stmt->execute([$adminId, $msg]);
            }

            $db->commit();
            sendJson(['success' => true, 'demande_id' => $newId]);

        } catch (Exception $e) {
            $db->rollBack();
            sendError('Erreur nouvelle demande : ' . $e->getMessage(), 500);
        }
    }

    sendError('Action non reconnue ou permissions insuffisantes', 400);
}

sendError('Méthode non autorisée', 405);