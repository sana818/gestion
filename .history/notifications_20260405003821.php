<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

function handleError($message, $code = 400) {
    ob_end_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}
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
    // Connexion à la base de données avec meilleure gestion d'erreurs
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    // Vérification de l'en-tête d'autorisation
    $authHeader = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    } else {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
    
    if (empty($authHeader)) {
        handleError('En-tête Authorization manquant', 401);
    }
    
    if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        handleError('Format du token JWT invalide', 401);
    }
    
    $jwt = trim($matches[1]);
    
    if (empty($jwt)) {
        handleError('Token JWT vide', 401);
    }
    
    $secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#";
    
    try {
        $decoded = JWT::decode($jwt, new Key($secretKey, 'HS256'));
    } catch (Exception $e) {
        handleError('Token JWT invalide: ' . $e->getMessage(), 401);
    }

    // Récupération de l'ID utilisateur
    $userId = null;
    if (isset($decoded->id)) {
        $userId = (int)$decoded->id;
    } elseif (isset($decoded->userId)) {
        $userId = (int)$decoded->userId;
    } elseif (isset($decoded->user_id)) {
        $userId = (int)$decoded->user_id;
    }
    
    if (!$userId) {
        handleError('ID utilisateur non trouvé dans le token', 401);
    }

    // Détermination de l'action
    $input = [];
    $jsonInput = file_get_contents('php://input');
    if (!empty($jsonInput)) {
        $input = json_decode($jsonInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            handleError('Données JSON invalides: ' . json_last_error_msg());
        }
    }
    
    $action = $_GET['action'] ?? $input['action'] ?? 'get_notifications';

    // Journalisation pour le débogage
    error_log("Action demandée: $action, UserID: $userId");

    switch ($action) {
        case 'get_notifications':
            getNotifications($db, $userId);
            break;
            
        case 'mark_read':
        case 'mark_all_read':
            markAllAsRead($db, $userId);
            break;
            
        case 'mark_one_read':
            markOneAsRead($db, $userId, $input);
            break;
            
        case 'get_unread_count':
            getUnreadCount($db, $userId);
            break;
            
        default:
            handleError('Action non reconnue: ' . $action, 400);
    }

} catch (PDOException $e) {
    error_log("Erreur PDO: " . $e->getMessage());
    handleError('Erreur base de données: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("Erreur générale: " . $e->getMessage());
    handleError('Erreur serveur: ' . $e->getMessage(), 500);
}

// ==================== FONCTIONS ====================

function getNotifications($db, $userId) {
    try {
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        if ($limit <= 0 || $limit > 100) {
            $limit = 20;
        }
        
        // Vérifier d'abord si la table existe et a les bonnes colonnes
        $stmt = $db->prepare("
            SELECT id, type, message, date, lu, lien 
            FROM notifications 
            WHERE destinataire_id = ? 
            ORDER BY date DESC 
            LIMIT ?
        ");
        
        $stmt->execute([$userId, $limit]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formater les dates pour un meilleur affichage
        foreach ($notifications as &$notification) {
            if (!empty($notification['date'])) {
                $notification['date_formatted'] = date('d/m/Y H:i', strtotime($notification['date']));
            }
            // Convertir lu en boolean pour JavaScript
            $notification['lu'] = (bool)$notification['lu'];
        }
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications
        ]);
        
    } catch (PDOException $e) {
        error_log("Erreur dans getNotifications: " . $e->getMessage());
        handleError('Erreur lors de la récupération des notifications: ' . $e->getMessage(), 500);
    }
}

function markAllAsRead($db, $userId) {
    try {
        $stmt = $db->prepare("
            UPDATE notifications 
            SET lu = 1 
            WHERE destinataire_id = ? AND lu = 0
        ");
        $stmt->execute([$userId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Toutes les notifications marquées comme lues',
            'updated' => $stmt->rowCount()
        ]);
        
    } catch (PDOException $e) {
        error_log("Erreur dans markAllAsRead: " . $e->getMessage());
        handleError('Erreur lors du marquage des notifications: ' . $e->getMessage(), 500);
    }
}

function markOneAsRead($db, $userId, $input) {
    try {
        if (empty($input['notification_id'])) {
            handleError('ID de notification manquant', 422);
        }
        
        $notificationId = (int)$input['notification_id'];
        
        if ($notificationId <= 0) {
            handleError('ID de notification invalide', 422);
        }
        
        $stmt = $db->prepare("
            UPDATE notifications 
            SET lu = 1 
            WHERE id = ? AND destinataire_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        
        if ($stmt->rowCount() === 0) {
            handleError('Notification non trouvée ou déjà lue', 404);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Notification marquée comme lue'
        ]);
        
    } catch (PDOException $e) {
        error_log("Erreur dans markOneAsRead: " . $e->getMessage());
        handleError('Erreur lors du marquage de la notification: ' . $e->getMessage(), 500);
    }
}

function getUnreadCount($db, $userId) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as unread_count 
            FROM notifications 
            WHERE destinataire_id = ? AND lu = 0
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'unread_count' => (int)$result['unread_count']
        ]);
        
    } catch (PDOException $e) {
        error_log("Erreur dans getUnreadCount: " . $e->getMessage());
        handleError('Erreur lors du comptage des notifications: ' . $e->getMessage(), 500);
    }
}
?>