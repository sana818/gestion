<?php
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
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'message' => $msg], $code);
}

/* ── JWT ── */
function validateAdmin(): object {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
                ?? $headers['authorization']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide', 401);
    }

    $role = strtolower($decoded->role ?? '');
    if (!in_array($role, ['responsable_rh', 'directeur', 'admin'])) {
        sendError('Accès refusé', 403);
    }

    return $decoded;
}

/* ── DB ── */
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur DB', 500);
}

$decoded = validateAdmin();

/* ════════════════════════════════════════
   GET
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $action = $_GET['action'] ?? '';

    /* ── get_employee : détail d'un seul employé ── */
    if ($action === 'get_employee') {
        $id = intval($_GET['id'] ?? 0);
        if (!$id) sendError('ID manquant');

        $stmt = $db->prepare("
            SELECT
                r.id,
                r.nom,
                r.prenom,
                r.email,
                r.role,
                r.numero_telephone,
                r.date_naissance,
                r.photo_profil AS photo,
                COALESCE(e.poste, r.poste, 'Non défini') AS poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) sendError('Employé non trouvé', 404);

        sendJson(['success' => true, 'employee' => $employee]);
    }

    /* ── get_salary : salaire d'un employé ── */
    if ($action === 'get_salary') {
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$employeeId) sendError('employee_id manquant');

        // Vérifier si la table salaires existe
        try {
            $stmt = $db->prepare("
                SELECT monthly_salary, hourly_rate, weekly_hours, effective_date, comment
                FROM salaires
                WHERE employe_id = ?
                ORDER BY effective_date DESC
            ");
            $stmt->execute([$employeeId]);
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $current = $history[0] ?? null;

            sendJson([
                'success' => true,
                'salary'  => $current,
                'history' => $history
            ]);
        } catch (Exception $e) {
            // Table salaires inexistante ou autre erreur
            sendJson(['success' => false, 'salary' => null, 'history' => []]);
        }
    }

    /* ── update_salary via GET non supporté, renvoi liste ── */

    /* ── Liste complète de tous les employés ── */
    $poste = $_GET['poste'] ?? '';
    if ($poste) {
        $stmt = $db->prepare("
            SELECT
                r.id,
                r.nom,
                r.prenom,
                r.email,
                r.role,
                r.numero_telephone,
                COALESCE(e.poste, r.poste, 'Non défini') AS poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
            WHERE COALESCE(e.poste, r.poste) = ?
            ORDER BY r.nom ASC
        ");
        $stmt->execute([$poste]);
    } else {
        $stmt = $db->query("
            SELECT
                r.id,
                r.nom,
                r.prenom,
                r.email,
                r.role,
                r.numero_telephone,
                COALESCE(e.poste, r.poste, 'Non défini') AS poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
            ORDER BY r.nom ASC
        ");
    }

    sendJson($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* ════════════════════════════════════════
   POST
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $raw   = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if ($raw && $input === null) sendError('JSON invalide');

    $action = $input['action'] ?? '';

    /* ── update_salary ── */
    if ($action === 'update_salary') {
        $employeeId   = intval($input['employee_id']   ?? 0);
        $monthlySalary = floatval($input['monthly_salary'] ?? 0);
        $hourlyRate   = floatval($input['hourly_rate']  ?? 0);
        $weeklyHours  = floatval($input['weekly_hours'] ?? 0);
        $effectiveDate = trim($input['effective_date']  ?? '');
        $comment      = trim($input['comment']          ?? '');

        if (!$employeeId || !$monthlySalary || !$hourlyRate || !$weeklyHours || !$effectiveDate) {
            sendError('Champs manquants pour la mise à jour du salaire');
        }

        try {
            // Créer la table si elle n'existe pas
            $db->exec("
                CREATE TABLE IF NOT EXISTS salaires (
                    id            INT AUTO_INCREMENT PRIMARY KEY,
                    employe_id    INT NOT NULL,
                    monthly_salary DECIMAL(10,2) NOT NULL,
                    hourly_rate   DECIMAL(10,2) NOT NULL,
                    weekly_hours  DECIMAL(5,2)  NOT NULL,
                    effective_date DATE NOT NULL,
                    comment       TEXT,
                    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (employe_id) REFERENCES registre(id) ON DELETE CASCADE
                )
            ");

            $stmt = $db->prepare("
                INSERT INTO salaires (employe_id, monthly_salary, hourly_rate, weekly_hours, effective_date, comment)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$employeeId, $monthlySalary, $hourlyRate, $weeklyHours, $effectiveDate, $comment]);

            sendJson(['success' => true, 'message' => 'Salaire mis à jour']);
        } catch (Exception $e) {
            sendError('Erreur mise à jour salaire : ' . $e->getMessage(), 500);
        }
    }

    /* ── MODIFICATION (id présent) ── */
    if (isset($input['id'])) {
        $id    = intval($input['id']);
        $nom   = trim($input['nom']   ?? '');
        $email = trim($input['email'] ?? '');
        $poste = trim($input['poste'] ?? '');
        $role  = trim($input['role']  ?? '');

        if (!$nom || !$email) sendError('Champs nom et email requis');

        try {
            $db->beginTransaction();

            // Mettre à jour registre
            $fields = ['nom = ?', 'email = ?'];
            $params = [$nom, $email];

            if ($role) {
                $fields[] = 'role = ?';
                $params[]  = $role;
            }

            $params[] = $id;
            $db->prepare("UPDATE registre SET " . implode(', ', $fields) . " WHERE id = ?")
               ->execute($params);

            // Mettre à jour le poste si fourni
            if ($poste) {
                $check = $db->prepare("SELECT id FROM emplois WHERE employe_id = ?");
                $check->execute([$id]);
                if ($check->fetch()) {
                    $db->prepare("UPDATE emplois SET poste = ? WHERE employe_id = ?")
                       ->execute([$poste, $id]);
                } else {
                    $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)")
                       ->execute([$id, $poste]);
                }
            }

            $db->commit();
            sendJson(['success' => true]);

        } catch (Exception $e) {
            $db->rollBack();
            sendError('Erreur modification : ' . $e->getMessage(), 500);
        }
    }

    /* ── AJOUT ── */
    $required = ['nom', 'prenom', 'email', 'mot_de_passe', 'poste'];
    foreach ($required as $f) {
        if (empty($input[$f])) sendError("Champ '$f' requis");
    }

    $nom    = trim($input['nom']);
    $prenom = trim($input['prenom']);
    $email  = trim($input['email']);
    $poste  = trim($input['poste']);
    $tel    = trim($input['numero_telephone'] ?? '');
    $mdp    = password_hash($input['mot_de_passe'], PASSWORD_BCRYPT);

    // Rôle : seul le directeur peut créer un RH directement
    $role = $input['role'] ?? 'employe';
    if ($role === 'responsable_rh' && strtolower($decoded->role) !== 'directeur') {
        $role = 'employe';
    }
    // Sécurité : seuls ces rôles sont autorisés à la création
    if (!in_array($role, ['employe', 'responsable_rh'])) {
        $role = 'employe';
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            INSERT INTO registre (nom, prenom, email, numero_telephone, mot_de_passe, role)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nom, $prenom, $email, $tel, $mdp, $role]);
        $id = $db->lastInsertId();

        $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)")
           ->execute([$id, $poste]);

        $db->commit();
        sendJson(['success' => true, 'id' => (int)$id, 'role' => $role]);

    } catch (Exception $e) {
        $db->rollBack();
        if (strpos($e->getMessage(), 'Duplicate') !== false) sendError('Email déjà utilisé', 409);
        sendError('Erreur serveur : ' . $e->getMessage(), 500);
    }
}

/* ════════════════════════════════════════
   DELETE
   ════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendError('ID manquant');

    $db->prepare("DELETE FROM registre WHERE id = ?")->execute([$id]);
    sendJson(['success' => true]);
}

sendError('Méthode non autorisée', 405);