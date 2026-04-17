

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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $raw   = file_get_contents("php://input");
    $input = json_decode($raw, true);
    if ($raw && $input === null) sendError("JSON invalide");

    $required = ['nom', 'prenom', 'email', 'mot_de_passe', 'poste'];
    foreach ($required as $f) {
        if (empty($input[$f])) sendError("Champ $f requis");
    }

    $nom    = trim($input['nom']);
    $prenom = trim($input['prenom']);
    $email  = trim($input['email']);
    $poste  = trim($input['poste']);
    $tel    = $input['numero_telephone'] ?? '';
    $mdp    = password_hash($input['mot_de_passe'], PASSWORD_BCRYPT);
    $role   = $input['role'] ?? 'employe';

    $decodedRole = strtolower($decoded->role ?? '');
    if ($role === 'responsable_rh' && !in_array($decodedRole, ['directeur', 'administrateur'])) {
        $role = 'employe';
    }

    try {
        // ✅ INSERT dans employes avec colonne poste
        $stmt = $db->prepare("
            INSERT INTO employes (nom, prenom, email, numero_telephone, mot_de_passe, role, poste, statut)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'actif')
        ");
        $stmt->execute([$nom, $prenom, $email, $tel, $mdp, $role, $poste]);
        $id = $db->lastInsertId();
        sendJson(['success' => true, 'id' => $id, 'role' => $role]);

    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) sendError("Email existe déjà", 409);
        sendError("Erreur serveur: " . $e->getMessage(), 500);
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