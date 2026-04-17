<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 🔹 1️⃣ Récupération du token JWT
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$jwt = str_replace('Bearer ', '', $authHeader);

if (!$jwt) {
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
    exit;
}

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Token invalide : ' . $e->getMessage()]);
    exit;
}

$userId = $decoded->id ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable dans le token']);
    exit;
}

// 🔹 2️⃣ Gérer les requêtes GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        global $conn;
        $pdo = $conn;
        $stmt = $pdo->prepare("
            SELECT j.*, e.nom, e.prenom 
            FROM justificatif_employe j
            JOIN employes e ON j.employe_id = e.id
            WHERE j.employe_id = ?
            ORDER BY j.date_envoi DESC
        ");
        $stmt->execute([$userId]);
        $justificatifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'justificatifs' => $justificatifs]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
        exit;
    }
}

// 🔹 3️⃣ Récupérer les données POST
$date = $_POST['date'] ?? null;
$heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? '09:00';
$heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
$duree_retard = $_POST['duree_retard'] ?? 0;
$raison = $_POST['raison'] ?? null;
$commentaire = $_POST['commentaire'] ?? null;
$duree = 1;

if (!$date || !$heure_arrivee_prevue || !$raison) {
    echo json_encode(['success' => false, 'message' => 'Tous les champs sont requis']);
    exit;
}

// 🔹 4️⃣ Gestion du fichier uploadé
$documentPath = null;
if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
    $uploadDir = 'uploads/justificatifs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileExtension = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
    $targetFile = $uploadDir . $fileName;
    
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
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
    }
}

// 🔹 5️⃣ Insertion en base
$statut = 'en attente';
$date_envoi = date('Y-m-d H:i:s');

try {
    $pdo = Database::connect();
    
    // Récupérer le nom de l'utilisateur
    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $nomComplet = ($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '');
    
    // Insertion avec tous les champs
    $stmt = $pdo->prepare("
        INSERT INTO justificatif_employe 
        (employe_id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue, duree_retard, duree, raison, commentaire, document, statut_lecture, date_envoi)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        $statut,
        $date_envoi
    ]);
    
    $justificatifId = $pdo->lastInsertId();
    
    // 🔹 6️⃣ Créer les notifications pour les administrateurs
    $stmt = $pdo->prepare("SELECT id FROM employes WHERE role IN ('directeur', 'admin')");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $dureeMin = intval($duree_retard);
    if ($dureeMin < 60) {
        $dureeTexte = $dureeMin . ' min';
    } else {
        $heures = floor($dureeMin / 60);
        $minutes = $dureeMin % 60;
        $dureeTexte = $heures . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
    }
    
    $message = $nomComplet . ' a signalé un retard le ' . date('d/m/Y', strtotime($date)) . ' (' . $dureeTexte . ')';
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
    
    echo json_encode(['success' => true, 'message' => 'Justificatif envoyé avec succès']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
}
?>