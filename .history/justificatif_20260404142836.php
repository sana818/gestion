<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

// ====================== RÉCUPÉRATION TOKEN ======================
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$jwt = '';
if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $jwt = $matches[1];
}

if (!$jwt) {
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
    exit;
}

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    $userId = $decoded->id ?? null;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Token invalide : ' . $e->getMessage()]);
    exit;
}

if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable dans le token']);
    exit;
}

// ====================== RÉCUPÉRATION DES DONNÉES ======================
$date                  = $_POST['date'] ?? null;
$heure_arrivee_reelle  = $_POST['heure_arrivee_reelle'] ?? null;
$heure_arrivee_prevue  = $_POST['heure_arrivee_prevue'] ?? null;
$duree_retard          = $_POST['duree_retard'] ?? null;
$raison                = $_POST['raison'] ?? null;
$commentaire           = $_POST['commentaire'] ?? null;

if (!$date || !$heure_arrivee_prevue || !$duree_retard || !$raison) {
    echo json_encode(['success' => false, 'message' => 'Tous les champs obligatoires sont requis']);
    exit;
}

// ====================== UPLOAD FICHIER ======================
$documentPath = null;
if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
    $uploadDir = 'uploads/justificatifs/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileExtension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
    $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
    $targetFile = $uploadDir . $fileName;

    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
    if (!in_array($_FILES['document']['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé']);
        exit;
    }
    if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Fichier trop volumineux (max 5 Mo)']);
        exit;
    }

    if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
        $documentPath = $targetFile;
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'upload du fichier']);
        exit;
    }
}

// ====================== INSERTION EN BASE ======================
try {
    $pdo = Database::connect();

    // Récupérer nom + prénom
    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $nomComplet = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?: 'Un employé';

    $statut = 'non lu';
    $date_envoi = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO justificatif_employe 
        (employe_id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue, duree_retard, 
         duree, raison, commentaire, document, statut_lecture, date_envoi)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $userId,
        $date,
        $heure_arrivee_reelle,
        $heure_arrivee_prevue,
        $duree_retard,
        1,                    // duree (jours)
        $raison,
        $commentaire,
        $documentPath,
        $statut,
        $date_envoi
    ]);

    $justificatifId = $pdo->lastInsertId();

    // ====================== NOTIFICATIONS ======================
    $dureeMin = intval($duree_retard);
    $dureeTexte = ($dureeMin < 60) ? $dureeMin . ' min' : floor($dureeMin / 60) . 'h' . ($dureeMin % 60 > 0 ? ' ' . ($dureeMin % 60) . 'min' : '');

    $message = $nomComplet . ' a signalé un retard le ' . date('d/m/Y', strtotime($date)) . ' (' . $dureeTexte . ')';
    $lien = '/admin_justificatifs.php?id=' . $justificatifId;

    $stmt = $pdo->prepare("SELECT id FROM employes WHERE role IN ('directeur', 'admin')");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($admins)) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (destinataire_id, type, message, lien, date, lu) 
            VALUES (?, 'retard', ?, ?, NOW(), 0)
        ");
        foreach ($admins as $admin) {
            $stmtNotif->execute([$admin['id'], $message, $lien]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Justificatif envoyé avec succès']);

} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Erreur serveur : ' . $e->getMessage()
    ]);
}
?>