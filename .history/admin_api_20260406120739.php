<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

ini_set('display_errors', 0);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendJson($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

function validateAdmin(): object {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$authHeader) sendError('Token manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide', 401);
    }

    // ✅ Accepte directeur, administrateur, responsable_rh
    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['responsable_rh', 'directeur', 'administrateur', 'admin'])) {
        sendError('Accès refusé', 403);
    }

    return $decoded;
}

try {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB', 500);
}

$decoded = validateAdmin();

/* ══ GET ══ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if (isset($_GET['action']) && $_GET['action'] === 'get_employee') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) sendError("ID manquant");

        $stmt = $db->prepare("
            SELECT id, nom, prenom, email, role,
                   numero_telephone, date_naissance, poste
            FROM employes WHERE id = ?
        ");
        $stmt->execute([$id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$employee) sendError("Employé non trouvé", 404);
        sendJson(['success' => true, 'employee' => $employee]);
    }

    // ✅ GET tous les employés - table employes, pas de JOIN
    $stmt = $db->query("
        SELECT id, nom, prenom, email, role,
               numero_telephone, poste, statut
        FROM employes
        ORDER BY nom ASC
    ");
    sendJson($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* ══ POST ══ */
/* ══ POST ══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $raw   = file_get_contents("php://input");
    $input = json_decode($raw, true);

    if ($raw && $input === null) {
        sendError("JSON invalide");
    }

    // ✅ إذا لم توجد action → إضافة موظف
    if (!isset($input['action'])) {

        $nom            = trim($input['nom'] ?? '');
        $prenom         = trim($input['prenom'] ?? '');
        $date_naissance = trim($input['date_naissance'] ?? '');
        $email          = trim($input['email'] ?? '');
        $telephone      = trim($input['numero_telephone'] ?? '');
        $mot_de_passe   = $input['mot_de_passe'] ?? '';
        $poste          = trim($input['poste'] ?? '');
        $rfid_code      = trim($input['rfid_code'] ?? '') ?: null;

        // ✅ Validation
        if (!$nom || !$prenom || !$date_naissance || !$email || !$telephone || !$mot_de_passe || !$poste) {
            sendError("Champs obligatoires manquants");
        }

        // ✅ Vérifier email unique
        $stmt = $db->prepare("SELECT id FROM employes WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            sendError("Cet email existe déjà", 409);
        }

        // ✅ Vérifier RFID unique (optionnel)
        if ($rfid_code) {
            $stmt = $db->prepare("SELECT id FROM employes WHERE rfid_code = ?");
            $stmt->execute([$rfid_code]);
            if ($stmt->fetch()) {
                sendError("Ce code RFID est déjà utilisé", 409);
            }
        }

        $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);

        try {
            $stmt = $db->prepare("
                INSERT INTO employes 
                (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, poste, rfid_code, role, statut, date_embauche)
                VALUES 
                (?, ?, ?, ?, ?, ?, ?, ?, 'employe', 'actif', CURDATE())
            ");

            $stmt->execute([
                $nom,
                $prenom,
                $date_naissance,
                $email,
                $telephone,
                $hash,
                $poste,
                $rfid_code
            ]);

            sendJson([
                'success' => true,
                'message' => 'Employé ajouté avec succès',
                'id' => $db->lastInsertId()
            ]);

        } catch (Exception $e) {
            sendError("Erreur serveur: " . $e->getMessage(), 500);
        }
    }

}

/* ══ DELETE ══ */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendError("ID manquant");
    $stmt = $db->prepare("DELETE FROM employes WHERE id = ?");
    $stmt->execute([$id]);
    sendJson(['success' => true]);
}

sendError("Méthode non autorisée", 405);