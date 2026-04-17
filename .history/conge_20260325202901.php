<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

// Gérer les requêtes OPTIONS (pre-flight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Configuration de la base de données - À MODIFIER SELON VOTRE CONFIGURATION
$db_host = 'localhost';
$db_name = 'gestion_utilisateurs'; // Nom de votre base de données
$db_user = 'root'; // Votre utilisateur MySQL
$db_pass = ''; // Votre mot de passe MySQL

// Clé secrète pour JWT - À MODIFIER
$secretKey = "Votre_Cle_Secrete_Complexe_Ici_123!@#";

function handleError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit();
}

try {
    // Connexion à la base de données
    $db = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ===== VÉRIFICATION JWT =====
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';

    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        handleError("Token manquant", 401);
    }

    $jwt = $matches[1];

    // Décoder le JWT (sans utiliser firebase/php-jwt pour simplifier)
    $tokenParts = explode('.', $jwt);
    if (count($tokenParts) != 3) {
        handleError("Format de token invalide", 401);
    }
    
    $payload = json_decode(base64_decode($tokenParts[1]), true);
    $employe_id = $payload['id'] ?? $payload['user_id'] ?? null;
    
    if (!$employe_id) {
        handleError("Utilisateur non identifié dans le token", 401);
    }

    // ===== TRAITEMENT SELON LA MÉTHODE HTTP =====
    
    // GET - Récupérer les données
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? '';
        
        // Récupérer l'historique des congés
        if ($action === 'historique') {
            
            $stmt = $db->prepare("
                SELECT 
                    id,
                    type_conge,
                    DATE_FORMAT(date_debut, '%Y-%m-%d') as date_debut,
                    DATE_FORMAT(date_fin, '%Y-%m-%d') as date_fin,
                    jours_demande,
                    commentaire,
                    statut,
                    DATE_FORMAT(date_demande, '%d/%m/%Y') as date_demande
                FROM conges 
                WHERE employe_id = :employe_id 
                ORDER BY date_demande DESC 
                LIMIT 10
            ");
            
            $stmt->execute([':employe_id' => $employe_id]);
            $conges = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'conges' => $conges
            ]);
            exit();
        }
        
        // Récupérer le solde des congés
        elseif ($action === 'solde') {
            
            // Récupérer tous les congés de l'employé
            $stmt = $db->prepare("
                SELECT 
                    type_conge,
                    SUM(CASE WHEN statut IN ('accepte', 'en_attente') THEN jours_demande ELSE 0 END) as utilises
                FROM conges 
                WHERE employe_id = :employe_id
                GROUP BY type_conge
            ");
            
            $stmt->execute([':employe_id' => $employe_id]);
            $utilises = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Définir les soldes par défaut selon le type de congé
            $soldes = [
                ['type' => 'Annuel', 'total' => 25, 'utilises' => 0, 'restants' => 25],
                ['type' => 'Maladie', 'total' => 15, 'utilises' => 0, 'restants' => 15],
                ['type' => 'Exceptionnel', 'total' => 7, 'utilises' => 0, 'restants' => 7]
            ];
            
            // Mettre à jour avec les données réelles
            foreach ($soldes as &$solde) {
                $type_key = strtolower($solde['type']);
                if (isset($utilises[$type_key])) {
                    $solde['utilises'] = (int)$utilises[$type_key];
                    $solde['restants'] = $solde['total'] - $solde['utilises'];
                }
            }
            
            echo json_encode([
                'success' => true,
                'solde' => $soldes
            ]);
            exit();
        }
        
        // Si action inconnue
        else {
            handleError("Action non reconnue", 400);
        }
    }

    // POST - Créer une nouvelle demande de congé
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // Récupération des données
        $input = json_decode(file_get_contents("php://input"), true);

        if (!$input) {
            handleError("Données JSON invalides", 400);
        }

        $type_conge = trim($input['type_conge'] ?? '');
        $date_debut = trim($input['date_debut'] ?? '');
        $date_fin = trim($input['date_fin'] ?? '');
        $commentaire = trim($input['commentaire'] ?? '');
        $certificat_base64 = $input['certificat'] ?? null;

        // Validation des champs obligatoires
        if (!$type_conge || !$date_debut || !$date_fin) {
            handleError("Tous les champs obligatoires sont requis", 422);
        }

        // Validation spécifique pour congé maladie
        if ($type_conge === 'maladie' && empty($certificat_base64)) {
            handleError("Un certificat médical est requis pour un congé maladie", 422);
        }

        // Validation des dates
        $dateDebutObj = new DateTime($date_debut);
        $dateFinObj = new DateTime($date_fin);
        $today = new DateTime(date('Y-m-d'));

        if ($dateFinObj < $dateDebutObj) {
            handleError("La date de fin doit être postérieure à la date de début", 422);
        }

        if ($dateDebutObj < $today) {
            handleError("La date de début ne peut pas être dans le passé", 422);
        }

        // Calcul du nombre de jours
        $interval = $dateDebutObj->diff($dateFinObj);
        $jours_demande = $interval->days + 1; // +1 pour inclure le premier jour

        // Vérifier les chevauchements de congés
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM conges 
            WHERE employe_id = :employe_id 
            AND statut IN ('en_attente', 'accepte')
            AND (
                (date_debut <= :date_fin AND date_fin >= :date_debut)
            )
        ");
        
        $stmt->execute([
            ':employe_id' => $employe_id,
            ':date_debut' => $date_debut,
            ':date_fin' => $date_fin
        ]);
        
        if ($stmt->fetchColumn() > 0) {
            handleError("Vous avez déjà une demande de congé pour cette période", 422);
        }

        // Vérifier le solde disponible
        if ($type_conge !== 'maladie') { // La maladie n'est pas comptée dans le solde
            $solde_disponible = 0;
            $total_annuel = 25; // À ajuster selon votre logique métier
            
            // Calculer le nombre de jours déjà utilisés pour ce type
            $stmt = $db->prepare("
                SELECT SUM(jours_demande) 
                FROM conges 
                WHERE employe_id = :employe_id 
                AND type_conge = :type_conge 
                AND statut IN ('accepte', 'en_attente')
            ");
            $stmt->execute([
                ':employe_id' => $employe_id,
                ':type_conge' => $type_conge
            ]);
            $utilises = $stmt->fetchColumn() ?: 0;
            
            $solde_disponible = $total_annuel - $utilises;
            
            if ($jours_demande > $solde_disponible) {
                handleError("Solde insuffisant. Il vous reste $solde_disponible jours", 422);
            }
        }

        // Traitement du certificat médical
        $certificat_path = null;
        if ($certificat_base64 && $type_conge === 'maladie') {
            // Créer le dossier uploads s'il n'existe pas
            $upload_dir = 'uploads/certificats/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Extraire les informations du fichier base64
            if (preg_match('/^data:([a-zA-Z0-9\/+]+);base64,/', $certificat_base64, $matches)) {
                $file_type = $matches[1];
                $certificat_base64 = substr($certificat_base64, strpos($certificat_base64, ',') + 1);
                $certificat_base64 = base64_decode($certificat_base64);
                
                // Déterminer l'extension
                $extension = 'bin';
                if (strpos($file_type, 'pdf') !== false) $extension = 'pdf';
                elseif (strpos($file_type, 'jpeg') !== false) $extension = 'jpg';
                elseif (strpos($file_type, 'png') !== false) $extension = 'png';
                
                // Générer un nom de fichier unique
                $filename = 'certificat_' . $employe_id . '_' . time() . '.' . $extension;
                $certificat_path = $upload_dir . $filename;
                
                // Sauvegarder le fichier
                file_put_contents($certificat_path, $certificat_base64);
            }
        }

        // Insertion dans la base de données
        $sql = "INSERT INTO conges (
            employe_id, 
            type_conge, 
            date_debut, 
            date_fin, 
            jours_demande, 
            commentaire, 
            certificat_medical, 
            statut, 
            date_demande
        ) VALUES (
            :employe_id, 
            :type_conge, 
            :date_debut, 
            :date_fin, 
            :jours_demande, 
            :commentaire, 
            :certificat_medical, 
            'en_attente', 
            NOW()
        )";

        $stmt = $db->prepare($sql);
        
        $params = [
            ':employe_id' => $employe_id,
            ':type_conge' => $type_conge,
            ':date_debut' => $date_debut,
            ':date_fin' => $date_fin,
            ':jours_demande' => $jours_demande,
            ':commentaire' => $commentaire ?: null,
            ':certificat_medical' => $certificat_path
        ];
        
        $result = $stmt->execute($params);

        if ($result) {
            $conge_id = $db->lastInsertId();
            
            echo json_encode([
                "success" => true,
                "message" => "Demande de congé envoyée avec succès",
                "id" => $conge_id,
                "jours" => $jours_demande
            ]);
        } else {
            handleError("Erreur lors de l'enregistrement", 500);
        }
        exit();
    }

    // Si on arrive ici, méthode non supportée
    handleError("Méthode non supportée", 405);

} catch (PDOException $e) {
    handleError("Erreur base de données : " . $e->getMessage(), 500);
} catch (Exception $e) {
    handleError("Erreur serveur : " . $e->getMessage(), 500);
}
?>