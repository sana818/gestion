<?php
/**
 * admin_api.php
 * CRUD employés — accessible aux rôles : responsable_rh, directeur
 */

require_once 'vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('SECRET_KEY', 'Votre_Cle_Secrete_Complexe_Ici_123!@#');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/* ── helpers ── */
function sendJson($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}
function sendError($msg, $code = 400) {
    sendJson(['success' => false, 'error' => $msg], $code);
}

/* ── JWT : responsable_rh OU directeur ── */
function validateAdmin(): object {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
                ?? $headers['authorization']
                ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader) sendError('Token JWT manquant', 401);
    if (!preg_match('/Bearer\s(\S+)/i', $authHeader, $m)) sendError('Format token invalide', 401);

    try {
        $decoded = JWT::decode(trim($m[1]), new Key(SECRET_KEY, 'HS256'));
    } catch (Exception $e) {
        sendError('Token invalide ou expiré : ' . $e->getMessage(), 401);
    }

    $role = strtolower($decoded->role ?? '');
    $allowed = ['responsable_rh', 'directeur', 'admin'];

    if (!in_array($role, $allowed)) {
        sendError('Accès non autorisé (rôle insuffisant)', 403);
    }

    return $decoded;
}

/* ── PDO ── */
try {
    $db = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    sendError('Erreur BDD : ' . $e->getMessage(), 500);
}

$decoded = validateAdmin();
$currentRole = strtolower($decoded->role ?? '');

switch ($_SERVER['REQUEST_METHOD']) {

    /* ══════════════════════════════════════
       GET — liste employés + actions de lecture
       ══════════════════════════════════════ */
    case 'GET':

        $action = $_GET['action'] ?? '';

        /* détails d'un employé */
        if ($action === 'get_employee') {
            $id = intval($_GET['id'] ?? 0);
            if (!$id) sendError('ID manquant', 422);

            $stmt = $db->prepare("
                SELECT
                    r.id, r.nom, r.prenom, r.email,
                    r.numero_telephone, r.date_naissance,
                    r.role, r.photo_profil,
                    COALESCE(e.poste, r.poste, 'Non défini') AS poste,
                    e.date_embauche
                FROM registre r
                LEFT JOIN emplois e ON r.id = e.employe_id
                WHERE r.id = ?
            ");
            $stmt->execute([$id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$employee) sendError('Employé introuvable', 404);

            $employee['photo'] = null;
            if (!empty($employee['photo_profil'])) {
                /* chemin relatif → URL directe */
                $employee['photo'] = $employee['photo_profil'];
            }
            unset($employee['photo_profil']);

            sendJson(['success' => true, 'employee' => $employee]);
        }

        /* salaire */
        if ($action === 'get_salary') {
            $eid = intval($_GET['employee_id'] ?? 0);
            if (!$eid) sendError('employee_id manquant', 422);

            try {
                $stmt = $db->prepare("
                    SELECT * FROM employee_salary
                    WHERE employee_id = ?
                    ORDER BY effective_date DESC LIMIT 1
                ");
                $stmt->execute([$eid]);
                $salary = $stmt->fetch(PDO::FETCH_ASSOC);

                $stmtH = $db->prepare("
                    SELECT * FROM employee_salary
                    WHERE employee_id = ?
                    ORDER BY effective_date DESC
                ");
                $stmtH->execute([$eid]);
                $history = $stmtH->fetchAll(PDO::FETCH_ASSOC);

                sendJson(['success' => true, 'salary' => $salary ?: [], 'history' => $history ?: []]);
            } catch (PDOException $e) {
                sendJson(['success' => true, 'salary' => [], 'history' => []]);
            }
        }

        /* mise à jour postes silencieuse */
        if ($action === 'update_postes') {
            sendJson(['success' => true]);
        }

        /* ── liste principale des employés ── */
        $poste  = $_GET['poste'] ?? '';

        /*
         * Le directeur voit TOUT le monde (y.c. les RH).
         * Le responsable_rh voit tous sauf les directeurs.
         */
        if ($currentRole === 'directeur') {
            $query  = "
                SELECT
                    r.id,
                    r.nom,
                    r.prenom,
                    r.email,
                    r.role,
                    r.numero_telephone,
                    r.date_naissance,
                    COALESCE(e.poste, r.poste, 'Non défini') AS poste,
                    e.date_embauche AS date_nomination
                FROM registre r
                LEFT JOIN emplois e ON r.id = e.employe_id
            ";
            $params = [];

            if ($poste !== '') {
                $query   .= " WHERE (e.poste = ? OR r.poste = ?)";
                $params[] = $poste;
                $params[] = $poste;
            }
        } else {
            /* responsable_rh : exclure les directeurs */
            $query  = "
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
                WHERE r.role != 'directeur'
            ";
            $params = [];

            if ($poste !== '') {
                $query   .= " AND (e.poste = ? OR r.poste = ?)";
                $params[] = $poste;
                $params[] = $poste;
            }
        }

        $query .= " ORDER BY r.nom ASC";

        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendJson($users);

    /* ══════════════════════════════════════
       POST — ajout / modification
       ══════════════════════════════════════ */
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) sendError('Corps JSON invalide', 400);

        $action = $input['action'] ?? '';

        /* mise à jour salaire */
        if ($action === 'update_salary') {
            $eid     = intval($input['employee_id']    ?? 0);
            $salary  = floatval($input['monthly_salary'] ?? 0);
            $hourly  = floatval($input['hourly_rate']    ?? 0);
            $weekly  = floatval($input['weekly_hours']   ?? 0);
            $date    = $input['effective_date'] ?? date('Y-m-d');
            $comment = $input['comment'] ?? '';

            if (!$eid || !$salary || !$hourly || !$weekly) sendError('Données manquantes', 422);

            try {
                $stmt = $db->prepare("
                    INSERT INTO employee_salary
                        (employee_id, monthly_salary, hourly_rate, weekly_hours, effective_date, comment)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$eid, $salary, $hourly, $weekly, $date, $comment]);
                sendJson(['success' => true, 'message' => 'Salaire mis à jour']);
            } catch (PDOException $e) {
                sendError('Erreur salaire : ' . $e->getMessage(), 500);
            }
        }

        /* mise à jour postes après suppression */
        if ($action === 'update_postes') {
            $deletedPoste = $input['deleted_poste'] ?? '';
            if ($deletedPoste) {
                $stmt = $db->prepare("UPDATE emplois SET poste = 'Non défini' WHERE poste = ?");
                $stmt->execute([$deletedPoste]);
            }
            sendJson(['success' => true]);
        }

        /* modification d'un utilisateur existant */
        if (isset($input['id'])) {
            $id    = intval($input['id']);
            $nom   = trim($input['nom']   ?? '');
            $email = trim($input['email'] ?? '');
            $poste = trim($input['poste'] ?? '');
            $role  = trim($input['role']  ?? '');

            if (!$nom || !$email) sendError('nom et email obligatoires', 400);

            /* seul le directeur peut changer un rôle */
            if ($role && $currentRole !== 'directeur') {
                $role = ''; /* ignoré silencieusement pour le RH */
            }

            $db->beginTransaction();
            try {
                if ($role) {
                    $stmt = $db->prepare("UPDATE registre SET nom = ?, email = ?, role = ? WHERE id = ?");
                    $stmt->execute([$nom, $email, $role, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE registre SET nom = ?, email = ? WHERE id = ?");
                    $stmt->execute([$nom, $email, $id]);
                }

                if ($poste) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM emplois WHERE employe_id = ?");
                    $stmt->execute([$id]);
                    if ($stmt->fetchColumn()) {
                        $stmt = $db->prepare("UPDATE emplois SET poste = ? WHERE employe_id = ?");
                        $stmt->execute([$poste, $id]);
                    } else {
                        $stmt = $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)");
                        $stmt->execute([$id, $poste]);
                    }
                }

                $db->commit();
                sendJson(['success' => true, 'message' => 'Utilisateur modifié']);
            } catch (Exception $e) {
                $db->rollBack();
                sendError('Erreur modification : ' . $e->getMessage(), 500);
            }
        }

        /* ajout d'un nouvel employé */
        $required = ['nom', 'prenom', 'email', 'mot_de_passe', 'poste'];
        foreach ($required as $f) {
            if (empty($input[$f])) sendError("Champ '$f' requis", 400);
        }

        $nom              = trim($input['nom']);
        $prenom           = trim($input['prenom']);
        $email            = trim($input['email']);
        $numero_telephone = trim($input['numero_telephone'] ?? '');
        $date_naissance   = $input['date_naissance'] ?? null;
        $mot_de_passe     = password_hash($input['mot_de_passe'], PASSWORD_BCRYPT);
        $poste            = trim($input['poste']);

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("
                INSERT INTO registre
                    (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, role)
                VALUES (?, ?, ?, ?, ?, ?, 'employe')
            ");
            $stmt->execute([
                $nom, $prenom, $date_naissance,
                $email, $numero_telephone, $mot_de_passe
            ]);
            $newId = $db->lastInsertId();

            $stmt = $db->prepare("INSERT INTO emplois (employe_id, poste) VALUES (?, ?)");
            $stmt->execute([$newId, $poste]);

            $db->commit();
            sendJson(['success' => true, 'message' => 'Employé ajouté', 'id' => $newId]);

        } catch (Exception $e) {
            $db->rollBack();
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                sendError('Cet email est déjà utilisé', 409);
            }
            sendError('Erreur ajout : ' . $e->getMessage(), 500);
        }

    /* ══════════════════════════════════════
       DELETE — suppression employé
       ══════════════════════════════════════ */
    case 'DELETE':
        $id = intval($_GET['id'] ?? 0);
        if (!$id) sendError('ID manquant', 400);

        /* empêcher l'auto-suppression */
        $currentId = $decoded->id ?? $decoded->user_id ?? null;
        if ($currentId && $currentId == $id) sendError('Vous ne pouvez pas vous supprimer vous-même', 403);

        $db->beginTransaction();
        try {
            foreach (['conges', 'emplois', 'justificatif_employe', 'notifications'] as $tbl) {
                $stmt = $db->prepare("DELETE FROM $tbl WHERE employe_id = ?");
                $stmt->execute([$id]);
            }
            $stmt = $db->prepare("DELETE FROM registre WHERE id = ?");
            $stmt->execute([$id]);

            $db->commit();
            sendJson(['success' => true, 'message' => 'Utilisateur supprimé']);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Erreur suppression : ' . $e->getMessage(), 500);
        }

    default:
        sendError('Méthode non autorisée', 405);
}