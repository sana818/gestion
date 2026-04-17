<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

function handleError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Connexion PDO
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Authentification JWT (du directeur/admin)
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError('Token JWT manquant', 401);
    }

    $jwt = $matches[1];
    $secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
    try {
        $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    } catch (Exception $e) {
        handleError('Token JWT invalide: ' . $e->getMessage(), 401);
    }

    // Lecture du JSON d'entrée
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        handleError('Données JSON invalides: ' . json_last_error_msg(), 400);
    }

    // Récupère l'id de la demande et le nouveau statut
    $demandeId = $input['demande_id'] ?? null;
    $nouveauStatut = $input['statut'] ?? null; // 'accepte' ou 'refuse'

    if (!$demandeId || !$nouveauStatut) {
        handleError('demande_id et statut sont requis', 422);
    }

    // Mise à jour du statut dans la table conges
    $stmt = $db->prepare("UPDATE conges SET statut = ? WHERE id = ?");
    $stmt->execute([$nouveauStatut, $demandeId]);

    // Récupération de l'employé concerné par la demande
    $stmt = $db->prepare("SELECT user_id FROM conges WHERE id = ?");
    $stmt->execute([$demandeId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && isset($user['user_id'])) {
        $employeId = $user['user_id'];
        $message = "Votre administrateur a " .
                   ($nouveauStatut == 'accepte' ? "accepté" : "refusé") .
                   " ta demande de congé.";
        $notifEmploye = $db->prepare("INSERT INTO notifications (destinataire_id, message, statut, created_at) VALUES (?, ?, 'non_lu', NOW())");
        $notifEmploye->execute([$employeId, $message]);
    }

    echo json_encode(['success' => true, 'message' => 'Statut mis à jour et notification envoyée à l\'employé.']);

} catch (PDOException $e) {
    handleError('Erreur base de données: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    handleError('Erreur: ' . $e->getMessage(), 500);
}
?>