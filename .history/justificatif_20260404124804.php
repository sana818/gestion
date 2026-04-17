
header('Content-Type: application/json; charset=utf-8');

// 🔥 عرض الأخطاء (استعمله فقط أثناء التطوير)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 🔹 1️⃣ Récupération du token JWT
$headers = getallheaders();

$jwt = '';

if (isset($headers['X-Token'])) {
    $jwt = $headers['X-Token'];
} elseif (isset($headers['Authorization'])) {
    $authHeader = $headers['Authorization'];
    if (strpos($authHeader, 'Bearer ') === 0) {
        $jwt = substr($authHeader, 7);
    }
}

if (!$jwt) {
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Token invalide']);
    exit;
}

$userId = $decoded->id ?? null;

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable']);
    exit;
}

// 🔹 2️⃣ Données POST
$date = $_POST['date'] ?? null;
$heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? null;
$heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
$duree_retard = $_POST['duree_retard'] ?? null;
$raison = $_POST['raison'] ?? null;
$commentaire = $_POST['commentaire'] ?? null;
$duree = 1;

if (!$date || !$heure_arrivee_reelle || !$heure_arrivee_prevue || !$duree_retard || !$raison) {
    echo json_encode(['success' => false, 'message' => 'Champs obligatoires manquants']);
    exit;
}

// 🔹 3️⃣ Upload fichier
$documentPath = null;

if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {

    $uploadDir = 'uploads/justificatifs/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $ext;
    $targetFile = $uploadDir . $fileName;

    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];

    if (!in_array($_FILES['document']['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Type fichier invalide']);
        exit;
    }

    if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Fichier trop grand']);
        exit;
    }

    if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
        $documentPath = $targetFile;
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur upload']);
        exit;
    }
}

// 🔹 4️⃣ Insertion DB
try {
    $pdo = Database::connect();

    // 🔥 récupérer utilisateur
    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
        exit;
    }

    // ✅ CORRECTION IMPORTANTE
    $nomComplet = $user['prenom'] . ' ' . $user['nom'];

    $statut = 'non lu';
    $statut_demande = 'en attente';
    $date_envoi = date('Y-m-d H:i:s');

    // ✅ INSERT CORRECT (toutes les colonnes)
    $stmt = $pdo->prepare("
        INSERT INTO justificatif_employe 
        (employe_id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue, duree_retard, duree, raison, commentaire, document, statut, date_envoi, statut_lecture)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $date,
        $heure_arrivee_reelle,
        $heure_arrivee_prevue,
        $duree_retard,
        $duree,
        $raison,
        $commentaire,
        $documentPath,
        $statut_demande,
        $date_envoi,
        $statut
    ]);

    $justificatifId = $pdo->lastInsertId();

    // 🔹 5️⃣ Notifications admin
    $stmt = $pdo->prepare("SELECT id FROM employes WHERE role = 'directeur'");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // format durée
    $dureeMin = intval($duree_retard);
    $dureeTexte = ($dureeMin < 60)
        ? $dureeMin . ' min'
        : floor($dureeMin / 60) . 'h ' . ($dureeMin % 60) . 'min';

    $message = $nomComplet . " a signalé un retard le " . date('d/m/Y', strtotime($date)) . " (" . $dureeTexte . ")";
    $lien = '/admin_justificatifs.php?id=' . $justificatifId;

    if (!empty($admins)) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (destinataire_id, type, message, lien, date, lu)
            VALUES (?, 'retard', ?, ?, NOW(), 0)
        ");

        foreach ($admins as $admin) {
            $stmtNotif->execute([$admin['id'], $message, $lien]);
        }
    }

    // ✅ SUCCESS JSON
    echo json_encode([
        'success' => true,
        'message' => 'Justificatif envoyé avec succès'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur : ' . $e->getMessage()
    ]);
}
?>