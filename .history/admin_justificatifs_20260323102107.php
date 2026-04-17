<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? '';
$jwt = str_replace('Bearer ', '', $authHeader);

if (!$jwt) {
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId  = $decoded->id   ?? null;
    $userRole = $decoded->role ?? null;

    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable']);
        exit;
    }

    $rolesPermis = ['admin', , 'responsable_rh'];
    if (!in_array(strtolower(trim($userRole)), $rolesPermis)) {
        echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Token invalide : ' . $e->getMessage()]);
    exit;
}

$pdo    = Database::connect();
$method = $_SERVER['REQUEST_METHOD'];

// ============================================================
// GET : Récupérer toutes les annonces
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
            LEFT JOIN registre u ON j.employe_id = u.id
            ORDER BY 
                CASE WHEN j.statut_lecture = 'non lu' THEN 0 ELSE 1 END,
                j.date_envoi DESC
        ");

        $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($justificatifs as &$j) {

            // Nom employé
            $j['nom_employe'] = trim($j['nom_employe'] ?? '');
            if (empty($j['nom_employe'])) $j['nom_employe'] = 'Employé inconnu';

            // Heure prévue
            if (empty($j['heure_arrivee_prevue'])) {
                $j['heure_arrivee_prevue'] = '09:00';
            } else {
                $j['heure_arrivee_prevue'] = substr($j['heure_arrivee_prevue'], 0, 5);
            }

            // Heure réelle
            if (!empty($j['heure_arrivee_reelle'])) {
                $j['heure_arrivee_reelle'] = substr($j['heure_arrivee_reelle'], 0, 5);
            }

            // Durée retard — duree_retard en priorité, sinon duree
            if (!empty($j['duree_retard'])) {
                $j['duree_retard'] = $j['duree_retard'];
            } elseif (!empty($j['duree'])) {
                $j['duree_retard'] = $j['duree'];
            } else {
                $j['duree_retard'] = '0';
            }

            // Raison
            if (empty($j['raison'])) $j['raison'] = 'Non spécifiée';

            // Commentaire
            if (empty($j['commentaire'])) $j['commentaire'] = '';

            // Document
            if (empty($j['document'])) $j['document'] = null;

            // IMPORTANT: Renommer statut_lecture en statut pour le frontend
            $j['statut'] = $j['statut_lecture'] ?? 'non lu';

            // created_at est déjà défini par l'alias dans la requête SQL

            // Nettoyer
            unset($j['statut_lecture'], $j['duree']);
        }

        $nonLus = count(array_filter($justificatifs, fn($j) => $j['statut'] === 'non lu'));

        echo json_encode([
            'success'       => true,
            'justificatifs' => $justificatifs,
            'new_count'     => $nonLus
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erreur BDD : ' . $e->getMessage()
        ]);
    }
}

// ============================================================
// POST : Actions
// ============================================================
elseif ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // Marquer UNE annonce comme lue
    if ($action === 'marquer_lu') {
        $id = intval($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'ID manquant']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                UPDATE justificatif_employe 
                SET statut_lecture = 'lu' 
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            // Récupérer le nouveau count pour mettre à jour le badge
            $countStmt = $pdo->query("SELECT COUNT(*) as count FROM justificatif_employe WHERE statut_lecture = 'non lu'");
            $count = $countStmt->fetch(PDO::FETCH_ASSOC)['count'];
            
            echo json_encode([
                'success' => true,
                'new_count' => $count
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // Marquer TOUTES les annonces comme lues
    elseif ($action === 'marquer_tous_lus') {
        try {
            $pdo->exec("
                UPDATE justificatif_employe 
                SET statut_lecture = 'lu' 
                WHERE statut_lecture = 'non lu'
            ");
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    // Compter les non lus
    elseif ($action === 'get_new_count') {
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM justificatif_employe 
                WHERE statut_lecture = 'non lu'
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode([
                'success'   => true,
                'new_count' => $result['count'] ?? 0
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    else {
        echo json_encode(['success' => false, 'message' => 'Action non reconnue : ' . $action]);
    }
}

else {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
}
?>