<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// CONNEXION BASE DE DONNÉES
// ============================================================
$host    = 'localhost';
$db      = 'gestion_utilisateurs';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn     = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur DB: ' . $e->getMessage()]);
    exit;
}

// ============================================================
// VÉRIFICATION TOKEN
// ============================================================
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

function verifyToken() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (empty($auth)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token non fourni']);
        return false;
    }
    return true;
}

// ============================================================
// HELPER : filtre de période
// ============================================================
function getPeriodFilter($period) {
    $now   = new DateTime();
    $year  = $now->format('Y');
    $month = $now->format('m');

    switch ($period) {
        case 'this-month':
            return ['where' => "AND YEAR(%s) = $year AND MONTH(%s) = $month", 'type' => 'month'];
        case 'last-month':
            $last = new DateTime('first day of last month');
            return ['where' => "AND YEAR(%s) = ".$last->format('Y')." AND MONTH(%s) = ".$last->format('m'), 'type' => 'month'];
        case 'this-year':
            return ['where' => "AND YEAR(%s) = $year", 'type' => 'year'];
        case 'all':
        default:
            return ['where' => '', 'type' => 'all'];
    }
}

function applyFilter($filterTemplate, $column) {
    return str_replace('%s', $column, $filterTemplate);
}

// ============================================================
// ACTION : get_statistics
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_statistics') {
    if (!verifyToken()) exit;

    $user_id = (int) ($_GET['user_id'] ?? 0);
    if (!$user_id) {
        echo json_encode(['success' => false, 'message' => 'Paramètre user_id manquant']);
        exit;
    }

    $period = 'all';
    $pf     = getPeriodFilter($period);

    try {
        // =========================
        // 1. CONGÉS
        // =========================
        $congeFilter = applyFilter($pf['where'], 'date_demande');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM conges WHERE employe_id = ? $congeFilter");
        $stmt->execute([$user_id]);
        $congesTotal = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT statut, COUNT(*) as nb FROM conges WHERE employe_id = ? $congeFilter GROUP BY statut");
        $stmt->execute([$user_id]);
        $congesStatuts = ['accepte' => 0, 'en_attente' => 0, 'refuse' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $s = strtolower(trim($row['statut'] ?? ''));
            if (in_array($s, ['accepte','accepté','approuve','approuvé','1'])) $congesStatuts['accepte'] += (int)$row['nb'];
            elseif (in_array($s, ['refuse','refusé','2'])) $congesStatuts['refuse'] += (int)$row['nb'];
            else $congesStatuts['en_attente'] += (int)$row['nb'];
        }

        $stmt = $pdo->prepare("SELECT type_conge, COUNT(*) as nb FROM conges WHERE employe_id = ? $congeFilter GROUP BY type_conge");
        $stmt->execute([$user_id]);
        $congesParType = ['annuel'=>0,'maladie'=>0,'exceptionnel'=>0,'maternite'=>0];
        foreach($stmt->fetchAll() as $row){
            $t = strtolower(trim($row['type_conge']));
            if(strpos($t,'annuel')!==false || strpos($t,'pay')!==false) $congesParType['annuel'] += (int)$row['nb'];
            elseif(strpos($t,'malad')!==false) $congesParType['maladie'] += (int)$row['nb'];
            elseif(strpos($t,'except')!==false || strpos($t,'spéc')!==false) $congesParType['exceptionnel'] += (int)$row['nb'];
            elseif(strpos($t,'matern')!==false || strpos($t,'patern')!==false) $congesParType['maternite'] += (int)$row['nb'];
            else $congesParType['exceptionnel'] += (int)$row['nb'];
        }

        $stmt = $pdo->prepare("SELECT type_conge as type, statut, date_demande as date, jours_demande as duree FROM conges WHERE employe_id = ? $congeFilter ORDER BY date_demande DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $congesRecents = $stmt->fetchAll();

        // =========================
        // 2. JUSTIFICATIFS
        // =========================
        $justifFilter = applyFilter($pf['where'], 'date_envoi');

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM justificatif_employe WHERE employe_id = ? $justifFilter");
        $stmt->execute([$user_id]);
        $justifTotal = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT statut, COUNT(*) as nb FROM justificatif_employe WHERE employe_id = ? $justifFilter GROUP BY statut");
        $stmt->execute([$user_id]);
        $justifStatuts = ['lus'=>0,'non_lus'=>0];
        foreach($stmt->fetchAll() as $row){
            $s = strtolower(trim($row['statut'] ?? ''));
            if($s==='lu') $justifStatuts['lus'] += (int)$row['nb'];
            else $justifStatuts['non_lus'] += (int)$row['nb'];
        }

        $stmt = $pdo->prepare("SELECT raison, COUNT(*) as nb FROM justificatif_employe WHERE employe_id = ? $justifFilter GROUP BY raison");
        $stmt->execute([$user_id]);
        $justifParCause = ['medical'=>0,'circulation'=>0,'transport'=>0,'famille'=>0,'autre'=>0];
        foreach($stmt->fetchAll() as $row){
            $r = strtolower(trim($row['raison']));
            if(strpos($r,'med')!==false || strpos($r,'santé')!==false || strpos($r,'docteur')!==false) $justifParCause['medical'] += (int)$row['nb'];
            elseif(strpos($r,'circul')!==false || strpos($r,'embouteill')!==false) $justifParCause['circulation'] += (int)$row['nb'];
            elseif(strpos($r,'transport')!==false || strpos($r,'bus')!==false || strpos($r,'train')!==false) $justifParCause['transport'] += (int)$row['nb'];
            elseif(strpos($r,'famil')!==false || strpos($r,'personne')!==false) $justifParCause['famille'] += (int)$row['nb'];
            else $justifParCause['autre'] += (int)$row['nb'];
        }

        $stmt = $pdo->prepare("SELECT raison as cause, statut, date_envoi as date, duree_retard as duree FROM justificatif_employe WHERE employe_id = ? $justifFilter ORDER BY date_envoi DESC LIMIT 5");
        $stmt->execute([$user_id]);
        $justifRecents = $stmt->fetchAll();
        foreach($justifRecents as &$jr){ if(!empty($jr['duree'])) $jr['duree'] .= ' min de retard'; } unset($jr);

        // =========================
        // 3. PRESENCE
        // =========================
        $presenceFilter = applyFilter($pf['where'], 'date');
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM presences WHERE employe_id = ? AND statut='présent' $presenceFilter");
        $stmt->execute([$user_id]);
        $joursTravailles = (int)$stmt->fetchColumn();

   
        // =========================
        // RÉPONSE FINALE
        // =========================
        echo json_encode([
            'success'=>true,
            'period'=>$period,
            'conges'=>[
                'total'=>$congesTotal,
                'approuves'=>$congesStatuts['accepte'],
                'en_attente'=>$congesStatuts['en_attente'],
                'refuses'=>$congesStatuts['refuse'],
                'par_type'=>$congesParType,
                'recents'=>$congesRecents
            ],
            'justificatifs'=>[
                'total'=>$justifTotal,
                'lus'=>$justifStatuts['lus'],
                'non_lus'=>$justifStatuts['non_lus'],
                'par_cause'=>$justifParCause,
                'recents'=>$justifRecents
            ],
            'presences'=>[
                'retards'=>$retards,
                'jours_travailles'=> $joursTravailles>0?$joursTravailles:20
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Erreur serveur: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Action non reconnue','available_actions'=>['get_statistics']]);
?>