<?php
require_once 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function sendError($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit();
}

function validateJWT() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token JWT manquant', 401);

    if (!preg_match('/Bearer\s(.+)/i', $authHeader, $matches)) {
        sendError('Format de token invalide', 401);
    }

    $jwt = trim($matches[1]);

    try {
        $decoded = JWT::decode($jwt, new Key(SECRET_KEY, 'HS256'));

        if (!isset($decoded->role) || strtolower($decoded->role) !== 'responsable rh') {
            sendError('Accès réservé aux directeurs', 403);
        }

        return $decoded;
    } catch (Exception $e) {
        sendError('Token invalide ou expiré: ' . $e->getMessage(), 401);
    }
}

try {
    $db = new PDO('mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8', 'root', '');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur de connexion à la base de données: ' . $e->getMessage(), 500);
}

$decoded = validateJWT();

switch ($_SERVER['REQUEST_METHOD']) {

    // ============================================================
    // GET
    // ============================================================
    case 'GET':

        $action = $_GET['action'] ?? '';

        // ✅ Détails d'un employé
        if ($action === 'get_employee') {
            $id = intval($_GET['id'] ?? 0);
            if (!$id) sendError('ID manquant', 422);

            $stmt = $db->prepare("
                SELECT 
                    r.id,
                    r.nom,
                    r.prenom,
                    r.email,
                    r.numero_telephone,
                    r.date_naissance,
                    r.role,
                    r.photo_profil,
                    COALESCE(e.poste, r.poste, 'Non défini') as poste,
                    e.date_embauche
                FROM registre r
                LEFT JOIN emplois e ON r.id = e.employe_id
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) sendError('Employé non trouvé', 404);

            if (!empty($employee['photo_profil'])) {
                $employee['photo'] = 'data:image/jpeg;base64,'
                    . base64_encode($employee['photo_profil']);
            } else {
                $employee['photo'] = null;
            }
            unset($employee['photo_profil']);

            echo json_encode(['success' => true, 'employee' => $employee]);
            exit();
        }

        // ✅ Salaire d'un employé
        if ($action === 'get_salary') {
            $employeeId = intval($_GET['employee_id'] ?? 0);
            if (!$employeeId) sendError('ID employé manquant', 422);

            try {
                $stmt = $db->prepare("
                    SELECT * FROM employee_salary 
                    WHERE employee_id = ? 
                    ORDER BY effective_date DESC 
                    LIMIT 1
                ");
                $stmt->execute([$employeeId]);
                $salary = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmtH = $db->prepare("
                    SELECT * FROM employee_salary 
                    WHERE employee_id = ? 
                    ORDER BY effective_date DESC
                ");
                $stmtH->execute([$employeeId]);
                $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);

                echo json_encode([
                    'success' => true,
                    'salary'  => $salary  ?: [],
                    'history' => $history ?: []
                ]);
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => true,
                    'salary'  => [],
                    'history' => []
                ]);
            }
            exit();
        }

        // ✅ Mise à jour des postes (action silencieuse)
        if ($action === 'update_postes') {
            echo json_encode(['success' => true]);
            exit();
        }

        // ✅ Par défaut : liste des utilisateurs avec filtre poste
        $poste  = $_GET['poste'] ?? '';
        $query  = "
            SELECT r.id, r.nom, r.email, r.role, e.poste
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id
        ";
        $params = [];

        if ($poste !== '') {
            $query   .= " WHERE e.poste = ?";
            $params[] = $poste;
        }

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($users);
        exit();

    // ============================================================
    // POST
    // ============================================================
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) sendError('Données JSON invalides', 400);

        $action = $input['action'] ?? '';

        // ✅ Mise à jour du salaire
        if ($action === 'update_salary') {
            $employeeId    = intval($input['employee_id']   ?? 0);
            $monthlySalary = floatval($input['monthly_salary'] ?? 0);
            $hourlyRate    = floatval($input['hourly_rate']    ?? 0);
            $weeklyHours   = floatval($input['weekly_hours']   ?? 0);
            $effectiveDate = $input['effective_date'] ?? date('Y-m-d');
            $comment       = $input['comment'] ?? '';

            if (!$employeeId || !$monthlySalary || !$hourlyRate || !$weeklyHours) {
                sendError('Données manquantes', 422);
            }

            try {
                $stmt = $db->prepare("
                    INSERT INTO employee_salary 
                        (employee_id, monthly_salary, hourly_rate, weekly_hours, effective_date, comment)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $employeeId, $monthlySalary, $hourlyRate,
                    $weeklyHours, $effectiveDate, $comment
                ]);

                echo json_encode(['success' => true, 'message' => 'Salaire mis à jour']);
            } catch (PDOException $e) {
                sendError('Erreur mise à jour salaire: ' . $e->getMessage(), 500);
            }
            exit();
        }

        // ✅ Mise à jour des postes après suppression
        if ($action === 'update_postes') {
            $deletedPoste = $input['deleted_poste'] ?? '';
            if ($deletedPoste) {
                $stmt = $db->prepare("
                    UPDATE emplois SET poste = 'Non défini' 
                    WHERE poste = ?
                ");
                $stmt->execute([$deletedPoste]);
            }
            echo json_encode(['success' => true]);
            exit();
        }

        // ✅ Modification d'un utilisateur existant
        if (isset($input['id'])) {
            $id    = $input['id'];
            $nom   = $input['nom']   ?? '';
            $email = $input['email'] ?? '';
            $poste = $input['poste'] ?? '';
            $role  = $input['role']  ?? '';

            if (empty($nom) || empty($email) || empty($role)) {
                sendError('Tous les champs sont obligatoires', 400);
            }

            $db->beginTransaction();
            try {
                $stmt = $db->prepare("UPDATE registre SET nom = ?, email = ?, role = ? WHERE id = ?");
                $stmt->execute([$nom, $email, $role, $id]);

                $stmt = $db->prepare("SELECT COUNT(*) FROM emplois WHERE employe_id = ?");
                $stmt->execute([$id]);
                $exists = $stmt->fetchColumn();

                if ($exists) {
                    $stmt = $db->prepare("UPDATE emplois SET poste = ? WHERE employe_id = ?");
                    $stmt->execute([$poste, $id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)");
                    $stmt->execute([$id, $poste]);
                }

                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Utilisateur modifié avec succès']);
            } catch (Exception $e) {
                $db->rollBack();
                sendError('Erreur lors de la modification: ' . $e->getMessage(), 500);
            }
            exit();
        }

        // ✅ Ajout d'un nouvel employé
        $required = ['nom', 'prenom', 'date_naissance', 'email', 'numero_telephone', 'mot_de_passe', 'poste'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                sendError("Le champ $field est requis", 400);
            }
        }

        $nom              = $input['nom'];
        $prenom           = $input['prenom'];
        $date_naissance   = $input['date_naissance'];
        $email            = $input['email'];
        $numero_telephone = $input['numero_telephone'];
        $mot_de_passe     = password_hash($input['mot_de_passe'], PASSWORD_DEFAULT);
        $poste            = $input['poste'];

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO registre (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, role)
                VALUES (:nom, :prenom, :date_naissance, :email, :numero_telephone, :mot_de_passe, 'employe')
            ");
            $stmt->execute([
                ':nom'              => $nom,
                ':prenom'           => $prenom,
                ':date_naissance'   => $date_naissance,
                ':email'            => $email,
                ':numero_telephone' => $numero_telephone,
                ':mot_de_passe'     => $mot_de_passe
            ]);

            $newUserId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)");
            $stmt->execute([$newUserId, $poste]);

            $db->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Employé ajouté avec succès',
                'id'      => $newUserId
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                sendError('Cet email est déjà utilisé', 400);
            } else {
                sendError('Erreur lors de l\'ajout: ' . $e->getMessage(), 500);
            }
        }
        exit();

    // ============================================================
    // DELETE
    // ============================================================
    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) sendError('ID manquant pour suppression', 400);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("DELETE FROM conges WHERE employe_id = ?");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM emplois WHERE employe_id = ?");
            $stmt->execute([$id]);

            $stmt = $db->prepare("DELETE FROM registre WHERE id = ?");
            $stmt->execute([$id]);

            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Utilisateur supprimé avec succès']);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Erreur lors de la suppression: ' . $e->getMessage(), 500);
        }
        exit();

    default:
        sendError('Méthode non autorisée', 405);
}
?>