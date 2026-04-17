<?php
require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

function handleError($message, $code = 400) {
    http_response_code($code);
    die(json_encode(['success' => false, 'error' => $message]));
}

try {
    // Connexion à la base de données
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Récupération du token JWT dans l'en-tête Authorization
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError('Token JWT manquant', 401);
    }

    $jwt = $matches[1];
    $secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#"; // À sécuriser en variable d'environnement
    $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));

    // Vérification du rôle
    if (!isset($decoded->role) || strtolower($decoded->role) !== 'directeur') {
        handleError('Accès réservé aux directeurs', 403);
    }

    // GESTION DES DIFFÉRENTES MÉTHODES
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Récupération des utilisateurs
        $posteFilter = isset($_GET['poste']) ? $_GET['poste'] : '';
        
        $sql = "SELECT r.id, r.nom, r.email, r.role, e.poste 
                FROM registre r 
                LEFT JOIN emplois e ON r.id = e.employe_id";
        
        $params = [];
        if (!empty($posteFilter)) {
            $sql .= " WHERE e.poste = ?";
            $params[] = $posteFilter;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($users);
        exit;
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Modification d'un utilisateur
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            handleError('Données JSON invalides', 400);
        }

        // Validation des champs
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $nom = trim($input['nom'] ?? '');
        $email = trim($input['email'] ?? '');
        $poste = trim($input['poste'] ?? '');
        $role = trim($input['role'] ?? '');

        if (!$id || !$nom || !$email || !$poste || !$role) {
            handleError('Tous les champs sont requis', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            handleError('Email invalide', 422);
        }

        // Vérifier que l'employé existe
        $stmtExist = $db->prepare("SELECT COUNT(*) FROM registre WHERE id = ?");
        $stmtExist->execute([$id]);
        if ($stmtExist->fetchColumn() == 0) {
            handleError("Employé avec l'id $id non trouvé", 404);
        }

        // Début de la transaction
        $db->beginTransaction();
        try {
            // Mise à jour de la table registre
            $stmt = $db->prepare("UPDATE registre SET nom = ?, email = ?, role = ? WHERE id = ?");
            $stmt->execute([$nom, $email, $role, $id]);

            // Vérifier si un poste existe déjà pour cet employé
            $stmtCheck = $db->prepare("SELECT COUNT(*) FROM emplois WHERE employe_id = ?");
            $stmtCheck->execute([$id]);
            $exists = $stmtCheck->fetchColumn();

            if ($exists) {
                // Mise à jour du poste
                $stmt = $db->prepare("UPDATE emplois SET poste = ? WHERE employe_id = ?");
                $stmt->execute([$poste, $id]);
            } else {
                // Insertion du poste
                $stmt = $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)");
                $stmt->execute([$id, $poste]);
            }

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Utilisateur modifié avec succès']);
        } catch (PDOException $e) {
            $db->rollBack();
            handleError('Erreur base de données: ' . $e->getMessage(), 500);
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Suppression d'un utilisateur
        $id = isset($_GET['id']) ? intval($_GET['id']) : null;
        
        if (!$id) {
            handleError('ID utilisateur manquant', 400);
        }
        
        // Vérifier que l'utilisateur existe
        $stmtCheck = $db->prepare("SELECT COUNT(*) FROM registre WHERE id = ?");
        $stmtCheck->execute([$id]);
        
        if ($stmtCheck->fetchColumn() == 0) {
            handleError("Utilisateur avec l'ID $id non trouvé", 404);
        }
        
        // Commencer une transaction
        $db->beginTransaction();
        
        try {
            // Supprimer d'abord les enregistrements liés dans emplois
            $stmt = $db->prepare("DELETE FROM emplois WHERE employe_id = ?");
            $stmt->execute([$id]);
            
            // Puis supprimer l'utilisateur
            $stmt = $db->prepare("DELETE FROM registre WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé avec succès']);
        } catch (PDOException $e) {
            $db->rollBack();
            handleError('Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
    }

} catch (Exception $e) {
    handleError($e->getMessage(), 500);
}
