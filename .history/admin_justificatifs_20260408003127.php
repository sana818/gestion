<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Connexion PDO directe (remplace Database.php)
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Erreur DB : ' . $e->getMessage()]);
    exit;
}

// JWT
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
    $decoded  = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId   = $decoded->id   ?? null;
    $userRole = $decoded->role ?? null;

    if (!$userId) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable']);
        exit;
    }

    $rolesPermis = ['admin', 'responsable_rh', 'directeur'];
    if (!in_array(strtolower(trim($userRole)), $rolesPermis)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
        exit;
    }

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Token invalide : ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET
// ============================================================
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT 
                j.id,
                j.employe_id,
                j.date_absence,
                j.heure_arrivee_reelle,
                j.heure_arrivee_prevue,
                j.duree_retard,
                j.duree,
                j.raison,
                j.commentaire,
                j.document,
                j.statut_lecture,
                j.date_envoi as created_at,
                CONCAT(u.prenom, ' ', u.nom) AS nom_employe,
                u.email,
                u.poste
            FROM justificatif_employe j
            LEFT JOIN employes u ON j.employe_id = u.id
            ORDER BY 
                CASE WHEN j.statut_lecture = 'non lu' THEN 0 ELSE 1 END,
                j.date_envoi DESC
        ");

        $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($justificatifs as &$j) {
            $j['nom_employe']       = trim($j['nom_employe'] ?? '') ?: 'Employé inconnu';
            $j['heure_arrivee_prevue'] = !empty($j['heure_arrivee_prevue']) ? substr($j['heure_arrivee_prevue'], 0, 5) : '09:00';
            if (!empty($j['heure_arrivee_reelle'])) $j['heure_arrivee_reelle'] = substr($j['heure_arrivee_reelle'], 0, 5);
            $j['duree_retard']      = $j['duree_retard'] ?? $j['duree'] ?? '0';
            $j['raison']            = $j['raison']      ?: 'Non spécifiée';
            $j['commentaire']       = $j['commentaire'] ?: '';
            $j['document']          = $j['document']    ?: null;
            $j['statut']            = $j['statut_lecture'] ?? 'non lu';
            unset($j['statut_lecture'], $j['duree']);
        }

        $nonLus = count(array_filter($justificatifs, fn($j) => $j['statut'] === 'non lu'));

        ob_end_clean();
        echo json_encode(['success' => true, 'justificatifs' => $justificatifs, 'new_count' => $nonLus]);

    } catch (PDOException $e) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Erreur BDD : ' . $e->getMessage()]);
    }

// ============================================================
// POST
// ============================================================
} elseif ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'marquer_lu') {
        $id = intval($input['id'] ?? 0);
        if (!$id) { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'ID manquant']); exit; }
        try {
            $pdo->prepare("UPDATE justificatif_employe SET statut_lecture = 'lu' WHERE id = ?")->execute([$id]);
            $count = $pdo->query("SELECT COUNT(*) FROM justificatif_employe WHERE statut_lecture = 'non lu'")->fetchColumn();
            ob_end_clean();
            echo json_encode(['success' => true, 'new_count' => $count]);
        } catch (PDOException $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }

    } elseif ($action === 'marquer_tous_lus') {
        try {
            $pdo->exec("UPDATE justificatif_employe SET statut_lecture = 'lu' WHERE statut_lecture = 'non lu'");
            ob_end_clean();
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }

    } else {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Action non reconnue']);
    }

} else {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
}
?>