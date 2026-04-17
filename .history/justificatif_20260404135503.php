<?php
header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

error_log('[justificatif.php] Démarrage du script');

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("[justificatif.php] ERREUR: [$errfile:$errline] $errstr");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur', 'debug' => $errstr]);
    exit;
});

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    error_log('[justificatif.php] Imports OK');

    // Récupération token — triple méthode (headers + POST + GET)
    $jwt = '';

    // Méthode 1 : Headers
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if ($headers !== false) {
            // Normaliser les clés en minuscules
            $headersLower = array_change_key_case($headers, CASE_LOWER);
            if (isset($headersLower['x-token'])) {
                $jwt = $headersLower['x-token'];
            } elseif (isset($headersLower['authorization'])) {
                $auth = $headersLower['authorization'];
                if (strpos($auth, 'Bearer ') === 0) {
                    $jwt = substr($auth, 7);
                }
            }
        }
    }

    // Méthode 2 : $_SERVER
    if (!$jwt) {
        if (isset($_SERVER['HTTP_X_TOKEN'])) {
            $jwt = $_SERVER['HTTP_X_TOKEN'];
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
            if (strpos($auth, 'Bearer ') === 0) {
                $jwt = substr($auth, 7);
            }
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            if (strpos($auth, 'Bearer ') === 0) {
                $jwt = substr($auth, 7);
            }
        }
    }

    // Méthode 3 : POST ou GET (fallback si headers bloqués)
    if (!$jwt && isset($_POST['token'])) {
        $jwt = $_POST['token'];
    }
    if (!$jwt && isset($_GET['token'])) {
        $jwt = $_GET['token'];
    }

    error_log('[justificatif.php] JWT trouvé: ' . ($jwt ? 'OUI' : 'NON'));

    if (!$jwt) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
        exit;
    }

    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    try {
        $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
        error_log('[justificatif.php] JWT décodé, ID=' . ($decoded->id ?? 'null'));
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token invalide: ' . $e->getMessage()]);
        exit;
    }

    $userId = $decoded->id ?? null;

    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable']);
        exit;
    }

    // GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        error_log('[justificatif.php] Méthode GET');
        $pdo = Database::connect();

        $stmt = $pdo->prepare("
            SELECT id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue,
                   duree_retard, raison, commentaire, document, statut, date_envoi, statut_lecture
            FROM justificatif_employe
            WHERE employe_id = ?
            ORDER BY date_envoi DESC
        ");
        $stmt->execute([$userId]);
        $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        error_log('[justificatif.php] GET OK, ' . count($justificatifs) . ' résultats');
        http_response_code(200);
        echo json_encode(['success' => true, 'justificatifs' => $justificatifs]);
        exit;
    }

    // POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
        exit;
    }

    error_log('[justificatif.php] Méthode POST');

    $date                 = $_POST['date'] ?? null;
    $heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? null;
    $heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
    $duree_retard         = $_POST['duree_retard'] ?? null;
    $raison               = $_POST['raison'] ?? null;
    $commentaire          = $_POST['commentaire'] ?? '';

    error_log('[justificatif.php] POST data: date=' . $date . ' raison=' . $raison);

    if (!$date || !$heure_arrivee_reelle || !$heure_arrivee_prevue || !$duree_retard || !$raison) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Champs obligatoires manquants',
            'received' => compact('date', 'heure_arrivee_reelle', 'heure_arrivee_prevue', 'duree_retard', 'raison')
        ]);
        exit;
    }

    // Upload fichier
    $documentPath = null;

    if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
        $uploadDir = __DIR__ . '/uploads/justificatifs/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];

        if (!in_array($_FILES['document']['type'], $allowedTypes)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Type fichier invalide']);
            exit;
        }

        if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Fichier trop grand (max 5 Mo)']);
            exit;
        }

        $ext        = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
        $fileName   = time() . '_' . uniqid() . '.' . $ext;
        $targetFile = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
            $documentPath = 'uploads/justificatifs/' . $fileName;
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur upload fichier']);
            exit;
        }
    }

    // Insertion DB
    $pdo = Database::connect();
    error_log('[justificatif.php] DB connectée');

    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
        exit;
    }

    $nomComplet     = $user['prenom'] . ' ' . $user['nom'];
    $statut_demande = 'en attente';
    $statut_lecture = 'non lu';
    $date_envoi     = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO justificatif_employe
        (employe_id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue,
         duree_retard, duree, raison, commentaire, document, statut, date_envoi, statut_lecture)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId, $date, $heure_arrivee_reelle, $heure_arrivee_prevue,
        $duree_retard, $raison, $commentaire, $documentPath,
        $statut_demande, $date_envoi, $statut_lecture
    ]);

    $justificatifId = $pdo->lastInsertId();
    error_log('[justificatif.php] INSERT OK, id=' . $justificatifId);

    // Notifications admins
    $stmt = $pdo->prepare("SELECT id FROM employes WHERE role = 'directeur'");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dureeMin   = intval($duree_retard);
    $dureeTexte = ($dureeMin < 60) ? $dureeMin . ' min' : floor($dureeMin / 60) . 'h ' . ($dureeMin % 60) . 'min';
    $message    = $nomComplet . " a signalé un retard le " . date('d/m/Y', strtotime($date)) . " (" . $dureeTexte . ")";
    $lien       = '/admin_justificatifs.php?id=' . $justificatifId;

    if (!empty($admins)) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (destinataire_id, type, message, lien, date, lu)
            VALUES (?, 'retard', ?, ?, NOW(), 0)
        ");
        foreach ($admins as $admin) {
            $stmtNotif->execute([$admin['id'], $message, $lien]);
        }
    }

    http_response_code(200);
    echo json_encode([
        'success'         => true,
        'message'         => 'Justificatif envoyé avec succès',
        'justificatif_id' => $justificatifId
    ]);

} catch (Exception $e) {
    error_log('[justificatif.php] EXCEPTION: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur: ' . $e->getMessage()
    ]);
}
?>
