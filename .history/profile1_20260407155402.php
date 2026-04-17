<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ===== AUTH =====
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    ob_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token manquant"]);
    exit;
}

$jwt        = $matches[1];
$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    ob_clean();
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Token invalide : " . $e->getMessage()]);
    exit;
}

$userId = $decoded->id;

// ===== DB =====
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ===== DONNÉES EMPLOYÉ =====
    $stmt = $pdo->prepare("
        SELECT 
            e.id,
            e.nom,
            e.prenom,
            e.date_naissance,
            e.email,
            e.numero_telephone,
            e.photo_profil,
            e.role,
            e.poste,
            e.statut,
            e.rfid_code,
            e.date_embauche,
            e.created_at,

            -- Dernière entrée (heure + date)
            (
                SELECT TIME_FORMAT(p1.heure_arrivee, '%H:%i')
                FROM presences p1
                WHERE p1.employe_id = e.id
                  AND p1.heure_arrivee IS NOT NULL
                  AND p1.heure_arrivee != '00:00:00'
                ORDER BY p1.date DESC, p1.heure_arrivee DESC
                LIMIT 1
            ) AS derniere_entree,

            (
                SELECT p2.date
                FROM presences p2
                WHERE p2.employe_id = e.id
                  AND p2.heure_arrivee IS NOT NULL
                  AND p2.heure_arrivee != '00:00:00'
                ORDER BY p2.date DESC
                LIMIT 1
            ) AS date_derniere_entree,

            -- Dernière sortie (heure + date)
            (
                SELECT TIME_FORMAT(p3.heure_depart, '%H:%i')
                FROM presences p3
                WHERE p3.employe_id = e.id
                  AND p3.heure_depart IS NOT NULL
                  AND p3.heure_depart != '00:00:00'
                ORDER BY p3.date DESC, p3.heure_depart DESC
                LIMIT 1
            ) AS derniere_sortie,

            (
                SELECT p4.date
                FROM presences p4
                WHERE p4.employe_id = e.id
                  AND p4.heure_depart IS NOT NULL
                  AND p4.heure_depart != '00:00:00'
                ORDER BY p4.date DESC
                LIMIT 1
            ) AS date_derniere_sortie,

            -- Horaires prévus (depuis la dernière présence)
            (
                SELECT p5.heure_arrivee_prevue
                FROM presences p5
                WHERE p5.employe_id = e.id
                ORDER BY p5.date DESC
                LIMIT 1
            ) AS heure_arrivee,

            (
                SELECT p6.heure_sortie_prevue
                FROM presences p6
                WHERE p6.employe_id = e.id
                ORDER BY p6.date DESC
                LIMIT 1
            ) AS heure_sortie,

            -- Salle (depuis la dernière présence)
            (
                SELECT p7.salle
                FROM presences p7
                WHERE p7.employe_id = e.id
                ORDER BY p7.date DESC
                LIMIT 1
            ) AS salle

        FROM employes e
        WHERE e.id = ?
    ");

    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ob_clean();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Utilisateur non trouvé"]);
        exit;
    }

    // ===== PHOTO DE PROFIL =====
    if (!empty($user['photo_profil'])) {
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($user['photo_profil']);
        $mimeType = $mimeType ?: 'image/jpeg';
        $user['photo_profil'] = 'data:' . $mimeType . ';base64,' . base64_encode($user['photo_profil']);
    } else {
        $prenom = $user['prenom'] ?? 'J';
        $nom    = $user['nom']    ?? 'D';
        $user['photo_profil'] = 'https://ui-avatars.com/api/?name=' . urlencode($prenom . ' ' . $nom) . '&size=180&background=2c3e50&color=fff&bold=true';
    }

    // ===== FORMATAGE DES LABELS DE DATE =====
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if (!empty($user['date_derniere_entree'])) {
        if ($user['date_derniere_entree'] === $today) {
            $user['label_entree'] = "Aujourd'hui";
        } elseif ($user['date_derniere_entree'] === $yesterday) {
            $user['label_entree'] = "Hier";
        } else {
            $user['label_entree'] = date('d M', strtotime($user['date_derniere_entree']));
        }
    } else {
        $user['label_entree'] = '—';
        $user['derniere_entree'] = '—';
    }

    if (!empty($user['date_derniere_sortie'])) {
        if ($user['date_derniere_sortie'] === $today) {
            $user['label_sortie'] = "Aujourd'hui";
        } elseif ($user['date_derniere_sortie'] === $yesterday) {
            $user['label_sortie'] = "Hier";
        } else {
            $user['label_sortie'] = date('d M', strtotime($user['date_derniere_sortie']));
        }
    } else {
        $user['label_sortie'] = '—';
        $user['derniere_sortie'] = '—';
    }

    // ===== FORMATAGE HORAIRES PRÉVUS =====
    if (!empty($user['heure_arrivee'])) {
        $user['heure_arrivee'] = substr($user['heure_arrivee'], 0, 5);
    }
    if (!empty($user['heure_sortie'])) {
        $user['heure_sortie'] = substr($user['heure_sortie'], 0, 5);
    }

    // ===== JOURS DE TRAVAIL (depuis employes si existant) =====
    // Si votre table employes a une colonne jours_travail, elle sera déjà dans $user
    // Sinon on peut la récupérer depuis presences.jours_travail
    if (empty($user['jours_travail'])) {
        $stmtJ = $pdo->prepare("
            SELECT jours_travail 
            FROM presences 
            WHERE employe_id = ? 
              AND jours_travail IS NOT NULL 
            ORDER BY date DESC 
            LIMIT 1
        ");
        $stmtJ->execute([$userId]);
        $row = $stmtJ->fetch(PDO::FETCH_ASSOC);
        $user['jours_travail'] = $row['jours_travail'] ?? null;
    }

    ob_clean();
    echo json_encode([
        "success" => true,
        "user"    => $user
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>