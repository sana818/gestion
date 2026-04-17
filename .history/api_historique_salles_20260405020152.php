<?php
// api_historique_salles.php - Compatible PHP 7 et PHP 8

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
ob_end_clean();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR))) {
        echo json_encode(array(
            'success'     => false,
            'fatal_error' => $error['message'],
            'line'        => $error['line']
        ));
    }
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================
$host     = 'localhost';
$dbname   = 'gestion_utilisateurs';
$username = 'root';
$password = '';
// ============================================================

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        )
    );

    // ----------------------------------------------------------
    // TEST : ?test=1
    // ----------------------------------------------------------
    if (isset($_GET['test'])) {
        echo json_encode(array(
            'success'     => true,
            'message'     => 'API opérationnelle',
            'base'        => $dbname,
            'time'        => date('Y-m-d H:i:s'),
            'php_version' => phpversion()
        ));
        exit;
    }

    // ----------------------------------------------------------
    // CHECK TABLES : ?action=check_tables
    // ----------------------------------------------------------
    if (isset($_GET['action']) && $_GET['action'] === 'check_tables') {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        $colonnes_historique = array();
        $colonnes_salles     = array();
        $colonnes_registre   = array();
        $apercu              = array();

        if (in_array('historique_salles', $tables)) {
            $colonnes_historique = $pdo->query("DESCRIBE historique_salles")->fetchAll();
            $apercu              = $pdo->query("SELECT * FROM historique_salles LIMIT 3")->fetchAll();
        }
        if (in_array('salles', $tables)) {
            $colonnes_salles = $pdo->query("DESCRIBE salles")->fetchAll();
        }
        if (in_array('registre', $tables)) {
            $colonnes_registre = $pdo->query("DESCRIBE registre")->fetchAll();
        }

        echo json_encode(array(
            'success'              => true,
            'tables'               => $tables,
            'colonnes_historique'  => $colonnes_historique,
            'colonnes_salles'      => $colonnes_salles,
            'colonnes_registre'    => $colonnes_registre,
            'apercu_historique'    => $apercu
        ));
        exit;
    }

    // ----------------------------------------------------------
    // GET HISTORIQUE : ?action=get_historique
    // Structure confirmée :
    //   historique_salles : id, employe_id, salle_id, date_entree, heure_entree, heure_sortie, created_at
    //   registre          : id, nom, prenom, ...
    //   salles            : id, nom, ...
    // ----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_historique') {

        // Vérification JWT
        $headers    = getallheaders();
        $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
        if (empty($authHeader)) {
            $authHeader = isset($headers['authorization']) ? $headers['authorization'] : '';
        }

        if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
            http_response_code(401);
            echo json_encode(array('success' => false, 'error' => 'Token manquant'));
            exit;
        }

        try {
            // Vérifier si la table salles existe
            $tables           = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $has_table_salles = in_array('salles', $tables);


            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $historique = $stmt->fetchAll();

            echo json_encode(array(
                'success'    => true,
                'count'      => count($historique),
                'historique' => $historique
            ));

        } catch (PDOException $e) {
            echo json_encode(array(
                'success' => false,
                'error'   => 'Erreur SQL : ' . $e->getMessage(),
                'conseil' => 'Appelez ?action=check_tables pour voir la structure complète'
            ));
        }
        exit;
    }

    // ----------------------------------------------------------
    // AJOUTER UN ACCÈS : POST ?action=add_access
    // Body JSON : { "employe_id": 1, "salle_id": 2 }
    // ----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add_access') {

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);
        if (!$data) $data = $_POST;

        $employe_id   = isset($data['employe_id'])  ? $data['employe_id']  : null;
        $salle_id     = isset($data['salle_id'])     ? $data['salle_id']    : null;
        $heure_entree = isset($data['heure_entree']) ? $data['heure_entree']: date('H:i:s');
        $date_entree  = isset($data['date_entree'])  ? $data['date_entree'] : date('Y-m-d H:i:s');
        $heure_sortie = isset($data['heure_sortie']) ? $data['heure_sortie']: null;

        if (!$employe_id || !$salle_id) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'employe_id et salle_id sont obligatoires'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO historique_salles (employe_id, salle_id, date_entree, heure_entree, heure_sortie)
                VALUES (:employe_id, :salle_id, :date_entree, :heure_entree, :heure_sortie)
            ");
            $stmt->execute(array(
                ':employe_id'   => $employe_id,
                ':salle_id'     => $salle_id,
                ':date_entree'  => $date_entree,
                ':heure_entree' => $heure_entree,
                ':heure_sortie' => $heure_sortie
            ));
            echo json_encode(array(
                'success' => true,
                'message' => 'Accès enregistré',
                'id'      => $pdo->lastInsertId()
            ));

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()));
        }
        exit;
    }

    // ----------------------------------------------------------
    // ENREGISTRER LA SORTIE : POST ?action=add_sortie
    // Body JSON : { "id": 5, "heure_sortie": "17:30:00" }
    // ----------------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add_sortie') {

        $input = file_get_contents('php://input');
        $data  = json_decode($input, true);
        if (!$data) $data = $_POST;

        $id           = isset($data['id'])           ? $data['id']           : null;
        $heure_sortie = isset($data['heure_sortie']) ? $data['heure_sortie'] : date('H:i:s');

        if (!$id) {
            http_response_code(400);
            echo json_encode(array('success' => false, 'error' => 'id est obligatoire'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE historique_salles SET heure_sortie = :heure_sortie WHERE id = :id");
            $stmt->execute(array(':heure_sortie' => $heure_sortie, ':id' => $id));
            echo json_encode(array('success' => true, 'message' => 'Heure de sortie enregistrée'));

        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(array('success' => false, 'error' => 'Erreur SQL : ' . $e->getMessage()));
        }
        exit;
    }

    // Action inconnue
    echo json_encode(array(
        'success'         => false,
        'error'           => 'Action non reconnue',
        'actions_valides' => array('get_historique', 'add_access', 'add_sortie', 'check_tables', 'test')
    ));

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error'   => 'Connexion BD impossible : ' . $e->getMessage(),
        'conseil' => 'Vérifiez que XAMPP est démarré et que la base "gestion_utilisateurs" existe'
    ));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array(
        'success' => false,
        'error'   => $e->getMessage(),
        'line'    => $e->getLine()
    ));
}
?>