<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

// Connexion à la base de données
$host = 'localhost';
$db   = 'gestion_utilisateurs';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Erreur connexion DB: '.$e->getMessage()]);
    exit;
}

// Fonction pour récupérer tous les headers
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// Fonction simplifiée de vérification de token
function verifyToken() {
    $headers = getallheaders();
    
    if (!isset($headers['Authorization'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token non fourni']);
        return false;
    }
    
    // Pour développement - accepter tout token
    // En production, vérifiez dans la base de données
    return ['id' => 1, 'role' => 'Directeur']; // Utilisateur admin par défaut
}

// Vérifier si c'est un administrateur
function verifyAdminToken() {
    $user = verifyToken();
    if (!$user) return false;
    
    if ($user['role'] !== 'Directeur') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Accès refusé. Administrateur requis.']);
        return false;
    }
    
    return $user;
}

// ============ ENDPOINTS ============

// Récupérer les détails d'un employé spécifique AVEC ses présences
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_employee') {
    if (!verifyAdminToken()) exit;
    
    if (!isset($_GET['id'])) {
        echo json_encode(['success' => false, 'message' => 'ID employé manquant']);
        exit;
    }
    
    $employee_id = (int)$_GET['id'];
    
    try {
        // 1. Récupérer les informations de l'employé depuis la table registre
        $stmt = $pdo->prepare("
            SELECT 
                id,
                nom,
                prenom,
                date_naissance,
                email,
                numero_telephone,
                role,
                photo_profil,
                poste,
                statut
            FROM registre 
            WHERE id = ? AND role != 'Directeur'
        ");
        $stmt->execute([$employee_id]);
        $employee = $stmt->fetch();
        
        if (!$employee) {
            echo json_encode(['success' => false, 'message' => 'Employé non trouvé']);
            exit;
        }
        
        // 2. Récupérer les présences de l'employé
        $stmt = $pdo->prepare("
            SELECT 
                id,
                employe,
                date,
                heure_arrivee,
                heure_depart,
                statut
            FROM presence 
            WHERE employe = ? 
            ORDER BY date DESC 
            LIMIT 30
        ");
        $stmt->execute([$employee_id]);
        $presences = $stmt->fetchAll();
        
        // 3. Récupérer les statistiques du mois en cours
        $current_month = date('m');
        $current_year = date('Y');
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_jours,
                SUM(CASE WHEN statut = 'présent' THEN 1 ELSE 0 END) as jours_presents,
                SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as jours_absents,
                SUM(CASE WHEN heure_arrivee IS NOT NULL AND heure_depart IS NOT NULL THEN 1 ELSE 0 END) as jours_complets
            FROM presence 
            WHERE employe = ? 
            AND MONTH(date) = ? 
            AND YEAR(date) = ?
        ");
        $stmt->execute([$employee_id, $current_month, $current_year]);
        $month_stats = $stmt->fetch();
        
        // 4. Calculer les heures travaillées ce mois-ci
        $stmt = $pdo->prepare("
            SELECT 
                heure_arrivee,
                heure_depart
            FROM presence 
            WHERE employe = ? 
            AND MONTH(date) = ? 
            AND YEAR(date) = ?
            AND statut = 'présent'
            AND heure_arrivee IS NOT NULL 
            AND heure_depart IS NOT NULL
        ");
        $stmt->execute([$employee_id, $current_month, $current_year]);
        $working_hours = $stmt->fetchAll();
        
        $total_heures = 0;
        $total_minutes = 0;
        
        foreach ($working_hours as $hour) {
            if ($hour['heure_arrivee'] && $hour['heure_depart']) {
                $start = new DateTime($hour['heure_arrivee']);
                $end = new DateTime($hour['heure_depart']);
                $interval = $start->diff($end);
                
                $total_heures += $interval->h;
                $total_minutes += $interval->i;
            }
        }
        
        // Convertir les minutes en heures
        $total_heures += floor($total_minutes / 60);
        $total_minutes = $total_minutes % 60;
        
        // 5. Récupérer le salaire si disponible
        $salary = null;
        $salary_history = [];
        
        try {
            // Vérifier si la table employee_salary existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'employee_salary'");
            if ($stmt->fetch()) {
                // Récupérer le salaire actuel
                $stmt = $pdo->prepare("
                    SELECT monthly_salary, hourly_rate, weekly_hours 
                    FROM employee_salary 
                    WHERE employee_id = ? 
                    ORDER BY effective_date DESC 
                    LIMIT 1
                ");
                $stmt->execute([$employee_id]);
                $salary = $stmt->fetch();
                
                // Récupérer l'historique
                $stmt = $pdo->prepare("
                    SELECT monthly_salary, hourly_rate, weekly_hours, effective_date, comment 
                    FROM employee_salary 
                    WHERE employee_id = ? 
                    ORDER BY effective_date DESC 
                    LIMIT 10
                ");
                $stmt->execute([$employee_id]);
                $salary_history = $stmt->fetchAll();
            }
        } catch (Exception $e) {
            // Table n'existe pas, on continue
        }
        
        // 6. Préparer la réponse
        $response = [
            'success' => true,
            'employee' => $employee,
            'presences' => $presences,
            'stats' => [
                'current_month' => [
                    'month' => $current_month,
                    'year' => $current_year,
                    'total_jours' => (int)$month_stats['total_jours'],
                    'jours_presents' => (int)$month_stats['jours_presents'],
                    'jours_absents' => (int)$month_stats['jours_absents'],
                    'jours_complets' => (int)$month_stats['jours_complets'],
                    'heures_travaillees' => $total_heures,
                    'minutes_travaillees' => $total_minutes,
                    'taux_presence' => $month_stats['total_jours'] > 0 ? 
                        round(($month_stats['jours_presents'] / $month_stats['total_jours']) * 100, 2) : 0
                ]
            ],
            'salary' => $salary ?: [
                'monthly_salary' => null,
                'hourly_rate' => null,
                'weekly_hours' => null
            ],
            'salary_history' => $salary_history
        ];
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur serveur: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
    exit;
}

// Récupérer TOUS les employés avec leurs statistiques
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_all_employees') {
    if (!verifyAdminToken()) exit;
    
    try {
        // 1. Récupérer tous les employés (sauf admin)
        $stmt = $pdo->query("
            SELECT 
                id,
                nom,
                prenom,
                email,
                numero_telephone,
                role,
                poste,
                statut,
                date_naissance
            FROM registre 
            WHERE role != 'Directeur'
            ORDER BY nom, prenom
        ");
        $employees = $stmt->fetchAll();
        
        // 2. Pour chaque employé, récupérer les statistiques du mois
        $current_month = date('m');
        $current_year = date('Y');
        
        foreach ($employees as &$employee) {
            $employee_id = $employee['id'];
            
            // Récupérer les statistiques de présence
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total_jours,
                    SUM(CASE WHEN statut = 'présent' THEN 1 ELSE 0 END) as jours_presents,
                    SUM(CASE WHEN statut = 'absent' THEN 1 ELSE 0 END) as jours_absents
                FROM presence 
                WHERE employe = ? 
                AND MONTH(date) = ? 
                AND YEAR(date) = ?
            ");
            $stmt->execute([$employee_id, $current_month, $current_year]);
            $stats = $stmt->fetch();
            
            // Calculer les heures travaillées
            $stmt = $pdo->prepare("
                SELECT 
                    heure_arrivee,
                    heure_depart
                FROM presence 
                WHERE employe = ? 
                AND MONTH(date) = ? 
                AND YEAR(date) = ?
                AND statut = 'présent'
                AND heure_arrivee IS NOT NULL 
                AND heure_depart IS NOT NULL
            ");
            $stmt->execute([$employee_id, $current_month, $current_year]);
            $hours = $stmt->fetchAll();
            
            $total_heures = 0;
            $total_minutes = 0;
            
            foreach ($hours as $hour) {
                if ($hour['heure_arrivee'] && $hour['heure_depart']) {
                    $start = new DateTime($hour['heure_arrivee']);
                    $end = new DateTime($hour['heure_depart']);
                    $interval = $start->diff($end);
                    
                    $total_heures += $interval->h;
                    $total_minutes += $interval->i;
                }
            }
            
            // Convertir minutes en heures
            $total_heures += floor($total_minutes / 60);
            $total_minutes = $total_minutes % 60;
            
            $employee['stats'] = [
                'total_jours' => (int)$stats['total_jours'],
                'jours_presents' => (int)$stats['jours_presents'],
                'jours_absents' => (int)$stats['jours_absents'],
                'heures_travaillees' => $total_heures,
                'minutes_travaillees' => $total_minutes,
                'taux_presence' => $stats['total_jours'] > 0 ? 
                    round(($stats['jours_presents'] / $stats['total_jours']) * 100, 2) : 0
            ];
        }
        
        echo json_encode([
            'success' => true,
            'employees' => $employees,
            'count' => count($employees),
            'month' => $current_month,
            'year' => $current_year
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur serveur: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Récupérer les présences de tous les employés pour une date spécifique
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_daily_presences') {
    if (!verifyAdminToken()) exit;
    
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id as presence_id,
                p.employe,
                p.date,
                p.heure_arrivee,
                p.heure_depart,
                p.statut,
                r.id as employee_id,
                r.nom,
                r.prenom,
                r.poste,
                r.email,
                r.numero_telephone
            FROM presence p
            LEFT JOIN registre r ON p.employe = r.id
            WHERE p.date = ?
            ORDER BY p.heure_arrivee ASC
        ");
        $stmt->execute([$date]);
        $presences = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'presences' => $presences,
            'date' => $date,
            'count' => count($presences)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur serveur: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Mettre à jour le salaire d'un employé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_salary') {
    if (!verifyAdminToken()) exit;
    
    $employee_id = (int)$_POST['employee_id'];
    $monthly_salary = (float)$_POST['monthly_salary'];
    $hourly_rate = (float)$_POST['hourly_rate'];
    $weekly_hours = (float)$_POST['weekly_hours'];
    $effective_date = $_POST['effective_date'];
    $comment = isset($_POST['comment']) ? $_POST['comment'] : '';
    
    try {
        // Vérifier si l'employé existe
        $stmt = $pdo->prepare("SELECT id FROM registre WHERE id = ? AND role != 'Directeur'");
        $stmt->execute([$employee_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Employé non trouvé']);
            exit;
        }
        
        // Créer la table si elle n'existe pas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_salary (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                monthly_salary DECIMAL(10,2) NOT NULL,
                hourly_rate DECIMAL(6,2) NOT NULL,
                weekly_hours DECIMAL(4,1) NOT NULL,
                effective_date DATE NOT NULL,
                comment TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (employee_id) REFERENCES registre(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        // Insérer le nouveau salaire
        $stmt = $pdo->prepare("
            INSERT INTO employee_salary 
            (employee_id, monthly_salary, hourly_rate, weekly_hours, effective_date, comment) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$employee_id, $monthly_salary, $hourly_rate, $weekly_hours, $effective_date, $comment]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Salaire mis à jour avec succès'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur serveur: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Récupérer les statistiques globales
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_global_stats') {
    if (!verifyAdminToken()) exit;
    
    $current_month = date('m');
    $current_year = date('Y');
    $today = date('Y-m-d');
    
    try {
        // 1. Nombre total d'employés
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM registre WHERE role != 'Directeur'");
        $total_employees = $stmt->fetch()['total'];
        
        // 2. Employés présents aujourd'hui
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT employe) as present_today 
            FROM presence 
            WHERE date = ? AND statut = 'présent'
        ");
        $stmt->execute([$today]);
        $present_today = $stmt->fetch()['present_today'];
        
        // 3. Employés absents aujourd'hui
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT employe) as absent_today 
            FROM presence 
            WHERE date = ? AND statut = 'absent'
        ");
        $stmt->execute([$today]);
        $absent_today = $stmt->fetch()['absent_today'];
        
        // 4. Congés en attente (si vous avez une table conges)
        $pending_leaves = 0;
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as pending FROM conges WHERE statut = 'en_attente'");
            $pending_leaves = $stmt->fetch()['pending'];
        } catch (Exception $e) {
            // Table n'existe pas
        }
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'total_employees' => (int)$total_employees,
                'present_today' => (int)$present_today,
                'absent_today' => (int)$absent_today,
                'pending_leaves' => (int)$pending_leaves,
                'taux_presence_today' => $total_employees > 0 ? 
                    round(($present_today / $total_employees) * 100, 2) : 0,
                'date' => $today,
                'month' => $current_month,
                'year' => $current_year
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erreur serveur: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Action non reconnue
echo json_encode([
    'success' => false, 
    'message' => 'Action non reconnue',
    'available_actions' => [
        'get_employee', 
        'get_all_employees', 
        'get_daily_presences', 
        'update_salary',
        'get_global_stats'
    ]
]);
?>