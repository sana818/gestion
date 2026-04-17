<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once 'Database.php';
require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secret_key = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

// ─────────────────────────────────────────────
// 1. Récupération & validation du token JWT
// ─────────────────────────────────────────────
$jwt = $_SERVER['HTTP_X_TOKEN'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$jwt = str_replace('Bearer ', '', $jwt);

if (!$jwt) {
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant']);
    exit;
}

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

// ─────────────────────────────────────────────
// 2. GET — récupérer les justificatifs
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
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

        echo json_encode(['success' => true, 'justificatifs' => $justificatifs]);
    } catch (Exception $e) {
        error_log('GET justificatifs error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur lors de la récupération des justificatifs']);
    }
    exit;
}

// ─────────────────────────────────────────────
// 3. POST — soumettre un justificatif
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Récupération des champs
    $date                = trim($_POST['date']                ?? '');
    $heure_arrivee_reelle = trim($_POST['heure_arrivee_reelle'] ?? '');
    $heure_arrivee_prevue = trim($_POST['heure_arrivee_prevue'] ?? '');
    $duree_retard        = trim($_POST['duree_retard']        ?? '');
    $raison              = trim($_POST['raison']              ?? '');
    $commentaire         = trim($_POST['commentaire']         ?? '');
    $duree               = 1; // durée en jours (valeur par défaut)

    // Validation des champs obligatoires
    if (!$date || !$heure_arrivee_reelle || !$heure_arrivee_prevue || $duree_retard === '' || !$raison) {
        echo json_encode(['success' => false, 'message' => 'Tous les champs obligatoires doivent être remplis (date, heures, durée de retard, raison)']);
        exit;
    }

    // Validation du format de date
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        echo json_encode(['success' => false, 'message' => 'Format de date invalide']);
        exit;
    }

    // Validation de la durée
    $duree_retard = intval($duree_retard);
    if ($duree_retard < 0) {
        echo json_encode(['success' => false, 'message' => 'La durée du retard ne peut pas être négative']);
        exit;
    }

    // ─────────────────────────────────────────
    // 4. Gestion de l'upload de fichier (optionnel)
    // ─────────────────────────────────────────
    $documentPath = null;

    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/justificatifs/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
        $allowedExts  = ['pdf', 'jpg', 'jpeg', 'png'];

        $fileMime = mime_content_type($_FILES['document']['tmp_name']);
        $fileExt  = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));

        if (!in_array($fileMime, $allowedMimes) || !in_array($fileExt, $allowedExts)) {
            echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé (PDF, JPG, PNG uniquement)']);
            exit;
        }

        if ($_FILES['document']['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'Le fichier ne doit pas dépasser 5 Mo']);
            exit;
        }

        $fileName = time() . '_' . uniqid() . '.' . $fileExt;
        $targetFile = $uploadDir . $fileName;

        if (!move_uploaded_file($_FILES['document']['tmp_name'], $targetFile)) {
            echo json_encode(['success' => false, 'message' => "Erreur lors de l'upload du fichier"]);
            exit;
        }

        $documentPath = $targetFile;
    }

    // ─────────────────────────────────────────
    // 5. Insertion en base de données
    // ─────────────────────────────────────────
    try {
        $pdo = Database::connect();

        // Récupérer le nom complet de l'employé
        $stmt = $pdo->prepare("SELECT nom, prenom FROM employes WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utilisateur introuvable']);
            exit;
        }

        // ✅ CORRECTION : définir $nomComplet
        $nomComplet  = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        $statut      = 'en attente';
        $date_envoi  = date('Y-m-d H:i:s');

        // Insertion principale
        $stmt = $pdo->prepare("
            INSERT INTO justificatif_employe
                (employe_id, date_absence, heure_arrivee_reelle, heure_arrivee_prevue,
                 duree_retard, duree, raison, commentaire, document, statut, date_envoi, statut_lecture)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'non lu')
        ");
        $stmt->execute([
            $userId,
            $date,
            $heure_arrivee_reelle,
            $heure_arrivee_prevue,
            $duree_retard,
            $duree,
            $raison,
            $commentaire ?: null,
            $documentPath,
            $statut,
            $date_envoi
        ]);

        $justificatifId = $pdo->lastInsertId();

        // ─────────────────────────────────────
        // 6. Notifications aux administrateurs
        // ─────────────────────────────────────
        $stmt = $pdo->prepare("
            SELECT id FROM employes
            WHERE role IN ('directeur', 'admin', 'rh')
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($admins)) {
            // Formater la durée pour le message
            $dureeMin = intval($duree_retard);
            if ($dureeMin < 60) {
                $dureeTexte = $dureeMin . ' min';
            } else {
                $heures  = floor($dureeMin / 60);
                $minutes = $dureeMin % 60;
                $dureeTexte = $heures . 'h' . ($minutes > 0 ? ' ' . $minutes . 'min' : '');
            }

            $message = $nomComplet . ' a signalé un retard le '
                     . date('d/m/Y', strtotime($date))
                     . ' (' . $dureeTexte . ')';
            $lien = '/admin_justificatifs.php?id=' . $justificatifId;

            $stmtNotif = $pdo->prepare("
                INSERT INTO notifications (destinataire_id, type, message, lien, date, lu)
                VALUES (?, 'retard', ?, ?, NOW(), 0)
            ");

            foreach ($admins as $admin) {
                $stmtNotif->execute([$admin['id'], $message, $lien]);
            }

            error_log('Notifications retard envoyées à ' . count($admins) . ' administrateur(s)');
        }

        echo json_encode([
            'success' => true,
            'message' => 'Justificatif envoyé avec succès',
            'id'      => $justificatifId
        ]);

    } catch (PDOException $e) {
        error_log('PDO error justificatif POST: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur base de données : ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log('Error justificatif POST: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
    }
    exit;
}

// Méthode non supportée
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);