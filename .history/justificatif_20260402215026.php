<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 🔹 1️⃣ Récupération du token JWT depuis X-Token
$jwt = $_SERVER['HTTP_X_TOKEN'] ?? '';
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

// 🔹 2️⃣ Récupérer les données POST (avec les nouveaux champs)
$date = $_POST['date'] ?? null;
$heure_arrivee_reelle = $_POST['heure_arrivee_reelle'] ?? null;
$heure_arrivee_prevue = $_POST['heure_arrivee_prevue'] ?? null;
$duree_retard = $_POST['duree_retard'] ?? null;
$raison = $_POST['raison'] ?? null;
$commentaire = $_POST['commentaire'] ?? null;
$duree = 1; // Valeur par défaut pour la durée en jours

// Validation des champs obligatoires
if (!$date || !$heure_arrivee_reelle || !$heure_arrivee_prevue || !$duree_retard || !$raison) {
    echo json_encode(['success' => false, 'message' => 'Tous les champs sont requis (date, heures, durée de retard, raison)']);
    exit;
}

// 🔹 3️⃣ Gestion du fichier uploadé (optionnel)
$documentPath = null;
if (isset($_FILES['document']) && $_FILES['document']['error'] == 0) {
    // Créer le dossier uploads s'il n'existe pas
    $uploadDir = 'uploads/justificatifs/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Générer un nom de fichier unique
    $fileExtension = pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION);
    $fileName = time() . '_' . uniqid() . '.' . $fileExtension;
    $targetFile = $uploadDir . $fileName;

    // Vérifier le type de fichier
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
    if (!in_array($_FILES['document']['type'], $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé (PDF, JPG, PNG uniquement)']);
        exit;
    }

    // Vérifier la taille du fichier (max 5 Mo)
    if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Le fichier ne doit pas dépasser 5 Mo']);
        exit;
    }

    if (move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
        $documentPath = $targetFile;
    } else {
        echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'upload du fichier']);
        exit;
    }
}

// 🔹 4️⃣ Insertion en base avec les nouveaux champs
$statut = 'non lu';
$date_envoi = date('Y-m-d H:i:s');

try {
    $pdo = Database::connect();

    // Récupérer le nom complet de l'utilisateur
    $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
        exit;
    }
    $nomComplet = trim($user['prenom'] . ' ' . $user['nom']);

    // Vérifier si les colonnes existent dans la table
    try {
        // Tentative d'insertion avec les nouvelles colonnes (y compris commentaire)
        $stmt = $pdo->prepare("
        INSERT INTO justificatif_employe 
        (employe_id, nom_employe, date_absence, heure_arrivee_reelle, heure_arrivee_prevue, duree_retard, duree, raison, commentaire, document, statut_lecture, date_envoi)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $nomComplet,
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
        
    } catch (PDOException $e) {
        // Si les colonnes n'existent pas, on essaie sans les nouveaux champs
        if ($e->getCode() == '42S22') { // ERREUR: colonne inconnue
            try {
                // Essai sans le commentaire d'abord
                $stmt = $pdo->prepare("
                    INSERT INTO justificatif_employe 
                    (employe_id, nom_employe, date_absence, heure_arrivee_reelle, heure_arrivee_prevue, duree_retard, duree, raison, document, statut_lecture, date_envoi)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $userId,
                    $nomComplet,
                    $date,
                    $heure_arrivee_reelle,
                    $heure_arrivee_prevue,
                    $duree_retard,
                    $duree,
                    $raison,
                    $documentPath,
                    $statut,
                    $date_envoi
                ]);
                
                $justificatifId = $pdo->lastInsertId();
                error_log("La colonne 'commentaire' n'existe pas dans la table justificatif_employe");
                
            } catch (PDOException $e2) {
                // Si encore erreur, on utilise la structure de base
                if ($e2->getCode() == '42S22') {
                    $stmt = $pdo->prepare("
                        INSERT INTO justificatif_employe 
                        (employe_id, nom_employe, date_absence, heure_arrivee_reelle, heure_arrivee_prevue, duree_retard, duree, raison, document, statut_lecture, date_envoi)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $userId,
                        $nomComplet,
                        $date,
                        $duree,
                        $raison,
                        $documentPath,
                        $statut,
                        $date_envoi
                    ]);
                    
                    $justificatifId = $pdo->lastInsertId();
                    error_log("Les nouvelles colonnes (heure_arrivee, duree_retard) n'existent pas dans la table justificatif_employe");
                    
                } else {
                    throw $e2;
                }
            }
        } else {
            // Autre erreur PDO
            throw $e;
        }
    }

    // ===== 🔥 AJOUT ICI : Créer les notifications pour les administrateurs =====
    
    // Récupérer tous les administrateurs
    $stmt = $pdo->prepare("
        SELECT id FROM e
        WHERE role IN ('directeur')
    ");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater la durée pour l'affichage
    $dureeMin = intval($duree_retard);
    if ($dureeMin < 60) {
        $dureeTexte = $dureeMin . ' min';
    } else {
        $heures = floor($dureeMin / 60);
        $minutes = $dureeMin % 60;
        $dureeTexte = $heures . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
    }
    
    // Message de notification
    $message = $nomComplet . ' a signalé un retard le ' . date('d/m/Y', strtotime($date)) . ' (' . $dureeTexte . ')';
    $lien = '/admin_justificatifs.php?id=' . $justificatifId;
    
    // Insérer une notification pour chaque admin
    if (!empty($admins)) {
        $stmtNotif = $pdo->prepare("
            INSERT INTO notifications (destinataire_id, type, message, lien, date, lu) 
            VALUES (?, 'retard', ?, ?, NOW(), 0)
        ");
        
        foreach ($admins as $admin) {
            $stmtNotif->execute([$admin['id'], $message, $lien]);
        }
        
        error_log("Notifications envoyées à " . count($admins) . " administrateurs");
    }

    echo json_encode(['success' => true, 'message' => 'Justificatif envoyé avec succès']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur SQL : ' . $e->getMessage()]);
}
?>