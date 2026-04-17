<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ============================================================
// 1. RÉCUPÉRATION ET VÉRIFICATION DU TOKEN JWT
// ============================================================
$headers    = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$jwt        = str_replace('Bearer ', '', $authHeader);

if (!$jwt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Token invalide : ' . $e->getMessage()]);
    exit;
}

// ============================================================
// 2. CONNEXION BASE DE DONNÉES
// ============================================================
try {
    $pdo = Database::connect();
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Erreur DB : ' . $e->getMessage()]);
    exit;
}

// ============================================================
// 3. RETROUVER LE VRAI ID EMPLOYÉ VIA L'EMAIL DU TOKEN
// ============================================================
$tokenEmail = $decoded->email ?? null;
$tokenId    = $decoded->id    ?? null;

$employe = null;

// Tentative 1 : via l'email du token
if ($tokenEmail) {
    $stmt = $pdo->prepare("SELECT id, nom, prenom, email FROM employes WHERE email = ? LIMIT 1");
    $stmt->execute([$tokenEmail]);
    $employe = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Tentative 2 : via l'id du token (au cas où les IDs correspondent)
if (!$employe && $tokenId) {
    $stmt = $pdo->prepare("SELECT id, nom, prenom, email FROM employes WHERE id = ? LIMIT 1");
    $stmt->execute([$tokenId]);
    $employe = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$employe) {
    ob_end_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Employé introuvable. Vérifiez que votre compte est bien enregistré.'
    ]);
    exit;
}

$employeId  = $employe['id'];
$nomComplet = trim(($employe['prenom'] ?? '') . ' ' . ($employe['nom'] ?? ''));

// ============================================================
// GET — Historique des justificatifs de l'employé
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                j.id,
                j.employe_id,
                j.date_absence,
                j.heure_arrivee_reelle,
                j.heure_arrivee_prevue,
                COALESCE(j.duree_retard, j.duree, 0) AS duree_retard,
                j.raison,
                j.commentaire,
                j.document,
                j.statut,
                j.statut_lecture,
                j.date_envoi,
                e.nom,
                e.prenom
            FROM justificatif_employe j
            JOIN employes e ON j.employe_id = e.id
            WHERE j.employe_id = ?
            ORDER BY j.date_envoi DESC
        ");
        $stmt->execute([$employeId]);
        $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        ob_end_clean();
        echo json_encode(['success' => true, 'justificatifs' => $justificatifs]);

    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// POST — Soumission d'un nouveau justificatif
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $date                 = $_POST['date']                 ?? null;
    $heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? '09:00';
    $heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
    $duree_retard         = intval($_POST['duree_retard']  ?? 0);
    $raison               = trim($_POST['raison']          ?? '');
    $commentaire          = trim($_POST['commentaire']     ?? '');

    if (!$date || !$heure_arrivee_prevue || !$raison) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Les champs date, heure prévue et raison sont obligatoires']);
        exit;
    }

    // ── Gestion du fichier uploadé ──────────────────────────
    $documentPath = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {

        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        $fileType     = mime_content_type($_FILES['document']['tmp_name']);

        if (!in_array($fileType, $allowedTypes)) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé (PDF, JPG, PNG uniquement)']);
            exit;
        }

        if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'Fichier trop volumineux (max 5 Mo)']);
            exit;
        }

        $uploadDir = __DIR__ . '/uploads/justificatifs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext      = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
        $fileName = time() . '_' . uniqid() . '.' . strtolower($ext);
        $target   = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['document']['tmp_name'], $target)) {
            $documentPath = 'uploads/justificatifs/' . $fileName;
        }
    }

    // ── Insertion en base ───────────────────────────────────
    try {
        $stmt = $pdo->prepare("
            INSERT INTO justificatif_employe 
                (employe_id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue,
                 duree_retard, duree, raison, commentaire, document, statut_lecture, date_envoi)
            VALUES 
                (?, ?, ?, ?, ?, 1, ?, ?, ?, 'non lu', NOW())
        ");

        $stmt->execute([
            $employeId,
            $date,
            $heure_arrivee_reelle,
            $heure_arrivee_prevue,
            $duree_retard,
            $raison,
            $commentaire ?: null,
            $documentPath
        ]);

        $justificatifId = $pdo->lastInsertId();

        // ── Notifications aux admins / RH ───────────────────
        $dureeMin  = $duree_retard;
        $dureeTexte = $dureeMin < 60
            ? $dureeMin . ' min'
            : floor($dureeMin / 60) . 'h' . ($dureeMin % 60 > 0 ? ' ' . ($dureeMin % 60) . 'min' : '');

        $message = $nomComplet . ' a signalé un retard le '
            . date('d/m/Y', strtotime($date))
            . ' (' . $dureeTexte . ')';

        $lien = '/admin_justificatifs.php?id=' . $justificatifId;

        $stmtAdmins = $pdo->prepare(
            "SELECT id FROM employes WHERE role IN ('directeur', 'admin', 'responsable_rh')"
        );
        $stmtAdmins->execute();
        $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($admins)) {
            $stmtNotif = $pdo->prepare("
                INSERT INTO notifications (destinataire_id, type, message, lien, date, lu)
                VALUES (?, 'retard', ?, ?, NOW(), 0)
            ");
            foreach ($admins as $admin) {
                $stmtNotif->execute([$admin['id'], $message, $lien]);
            }
        }

        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Justificatif envoyé avec succès',
            'id'      => $justificatifId
        ]);

    } catch (Exception $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
    exit;
}

// Méthode non supportée
ob_end_clean();
echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);