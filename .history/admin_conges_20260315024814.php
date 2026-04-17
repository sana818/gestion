<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;

use Firebase\JWT\Key;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function handleError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

// Fonction pour notifier l'employé de la décision
function notifierEmploye($employe_id, $demande_id, $decision, $dates) {
    try {
        $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // ✅ Message avec emoji
        $statut     = $decision === 'accepte' ? 'acceptée ' : 'refusée ';
        $date_debut = date('d/m/Y', strtotime($dates['debut']));
        $date_fin   = date('d/m/Y', strtotime($dates['fin']));
        $message    = "Votre demande de congé du $date_debut au $date_fin a été $statut";

        // ✅ Avec type = 'conge'
        $sql  = "INSERT INTO notifications (destinataire_id, type, message, date, lu) 
                 VALUES (?, 'conge', ?, NOW(), 0)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$employe_id, $message]);

        error_log("Notification envoyée à l'employé ID: $employe_id — $message");
        return true;

    } catch (PDOException $e) {
        error_log("Erreur notifierEmploye: " . $e->getMessage());
        return false;
    }
}

// Fonction pour notifier le directeur d'une nouvelle demande
function notifierDirecteurNouvelleDemande($employe_id, $demande_id, $employe_nom, $dates) {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $date_debut = date('d/m/Y', strtotime($dates['debut']));
    $date_fin = date('d/m/Y', strtotime($dates['fin']));
    $message = "Nouvelle demande de congé (ID: $demande_id) de l'employé $employe_nom du $date_debut au $date_fin";
    
    // ID du directeur (généralement 1)
    $directeur_id = 1;
    
    $sql = "INSERT INTO notifications (destinataire_id, date, message, lu) VALUES (?, NOW(), ?, 0)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$directeur_id, $message]);
    
    return true;
}

try {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- JWT ---
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError("Token JWT manquant", 401);
    }
    $jwt = $matches[1];
    $secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

    try {
        $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    } catch (Exception $e) {
        handleError('Token JWT invalide: ' . $e->getMessage(), 401);
    }

    // Vérifier si c'est un directeur OU un employé (pour les notifications)
    $isDirecteur = isset($decoded->role) && in_array(strtolower($decoded->role), ['directeur', 'admin']);
    $isEmploye = isset($decoded->role) && strtolower($decoded->role) === 'employe';

    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'get_conges' && $isDirecteur) {
            $stmt = $db->prepare("
                SELECT 
                    c.id,
                    c.employe_id,
                    r.nom,
                    r.prenom,
                    CONCAT(r.prenom, ' ', r.nom) AS employe,
                    c.type_conge AS type,
                    c.date_debut,
                    c.date_fin,
                    c.jours_demande AS nb_jours,
                    c.statut,
                    c.commentaire AS commentaire_admin,
                    c.certificat_medical,
                    c.date_demande AS created_at
                FROM conges c
                JOIN registre r ON c.employe_id = r.id
                ORDER BY c.date_demande DESC
            ");
            $stmt->execute();
            $conges = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $conges]);
            exit();
        } else {
            handleError('Action non reconnue ou permissions insuffisantes', 400);
        }
    }

    // Traitement des requêtes POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // Traitement d'une demande de congé par le directeur
        if ($input['action'] === 'traiter_conge' && $isDirecteur) {
            $demande_id = $input['demande_id'];
            $statut = $input['statut'];
            $commentaire = $input['commentaire'] ?? '';

            // Mettre à jour la demande de congé
            $stmt = $db->prepare("UPDATE conges SET statut = ?, commentaire = ? WHERE id = ?");
            $stmt->execute([$statut, $commentaire, $demande_id]);

            // Récupérer les infos de la demande pour la notification
            $stmt = $db->prepare("
                SELECT c.employe_id, c.date_debut, c.date_fin, r.nom, r.prenom 
                FROM conges c 
                JOIN registre r ON c.employe_id = r.id 
                WHERE c.id = ?
            ");
            $stmt->execute([$demande_id]);
            $demande = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($demande) {
                // Notifier l'employé de la décision
                $employe_id = $demande['employe_id'];
                $dates = [
                    'debut' => $demande['date_debut'],
                    'fin' => $demande['date_fin']
                ];
                notifierEmploye($employe_id, $demande_id, $statut, $dates);
            }

            echo json_encode(['success' => true]);
            exit();
        }
        // Nouvelle demande de congé par un employé
        else if ($input['action'] === 'nouvelle_demande' && $isEmploye) {
            $employe_id = $decoded->user_id ?? $input['employe_id'];
            $type_conge = $input['type_conge'];
            $date_debut = $input['date_debut'];
            $date_fin = $input['date_fin'];
            $jours_demande = $input['jours_demande'];
            $commentaire = $input['commentaire'] ?? '';

            // Insérer la demande
            $stmt = $db->prepare("
                INSERT INTO conges (employe_id, type_conge, date_debut, date_fin, jours_demande, commentaire, statut, date_demande) 
                VALUES (?, ?, ?, ?, ?, ?, 'en_attente', NOW())
            ");
            $stmt->execute([$employe_id, $type_conge, $date_debut, $date_fin, $jours_demande, $commentaire]);
            
            $demande_id = $db->lastInsertId();

            // Récupérer le nom de l'employé
            $stmt = $db->prepare("SELECT nom, prenom FROM registre WHERE id = ?");
            $stmt->execute([$employe_id]);
            $employe = $stmt->fetch(PDO::FETCH_ASSOC);
            $employe_nom = $employe ? $employe['prenom'] . ' ' . $employe['nom'] : 'Employé';

            // Notifier le directeur
            $dates = ['debut' => $date_debut, 'fin' => $date_fin];
            notifierDirecteurNouvelleDemande($employe_id, $demande_id, $employe_nom, $dates);

            echo json_encode(['success' => true, 'demande_id' => $demande_id]);
            exit();
        } else {
            handleError('Action non reconnue ou permissions insuffisantes', 400);
        }
    }

} catch (PDOException $e) {
    handleError("Erreur base de données : " . $e->getMessage(), 500);
} catch (Exception $e) {
    handleError("Erreur serveur : " . $e->getMessage(), 500);
}
?>