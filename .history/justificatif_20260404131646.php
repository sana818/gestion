<?php
header('Content-Type: application/json; charset=utf-8');

// 🔥 Gestion des erreurs
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

// Capturer les erreurs PHP
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur',
        'debug' => "[$errfile:$errline] $errstr"
    ]);
    exit;
});

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
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
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
        exit;
    }
    
    $secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
    
    try {
        $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token invalide']);
        exit;
    }
    
    $userId = $decoded->id ?? null;
    
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable']);
        exit;
    }
    
    // 🔹 Vérifier la méthode HTTP
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 📖 GET - Récupérer les justificatifs
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
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'justificatifs' => $justificatifs
        ]);
        exit;
    }
    
    // 🔹 POST - Créer un nouveau justificatif
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
        exit;
    }
    
    // 🔹 2️⃣ Données POST
    $date = $_POST['date'] ?? null;
    $heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? null;
    $heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
    $duree_retard = $_POST['duree_retard'] ?? null;
    $raison = $_POST['raison'] ?? null;
    $commentaire = $_POST['commentaire'] ?? '';
    $duree = 1;
    
    if (!$date || !$heure_arrivee_reelle || !$heure_arrivee_prevue || !$duree_retard || !$raison) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Champs obligatoires manquants',
            'received' => [
                'date' => $date,
                'heure_arrivee_reelle' => $heure_arrivee_reelle,
                'heure_arrivee_prevue' => $heure_arrivee_prevue,
                'duree_retard' => $duree_retard,
                'raison' => $raison
            ]
        ]);
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
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Type fichier invalide']);
            exit;
        }
        
        if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Fichier trop grand']);
            exit;
        }
        
        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
            $documentPath = $targetFile;
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erreur upload']);
            exit;
        }
    }
    
    // 🔹 4️⃣ Insertion DB
    $pdo = Database::connect();
    
    // Récupérer utilisateur
    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
        exit;
    }
    
    $nomComplet = $user['prenom'] . ' ' . $user['nom'];
    
    $statut = 'non lu';
    $statut_demande = 'en attente';
    $date_envoi = date('Y-m-d H:i:s');
    
    // INSERT
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
    
    // Format durée
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
    
    // ✅ SUCCESS
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Justificatif envoyé avec succès',
        'justificatif_id' => $justificatifId
    ]);
    
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur serveur'
    ]);
}
?>