<?php
require_once(__DIR__ . '/Database.php');

class User {
    public $nom;
    public $prenom;
    public $date_naissance;
    public $email;
    public $numero_telephone;
    public $mot_de_passe;
    public $poste;
    public $date_embauche;
    public $role;

    // Constructeur
    public function __construct($nom, $prenom, $date_naissance, $email, $numero_telephone, $password, $poste, $role, $date_embauche) {
        $this->nom = $nom;
        $this->prenom = $prenom;
        $this->date_naissance = $date_naissance;
        $this->email = $email;
        $this->numero_telephone = $numero_telephone;
        $this->role = $role;
        $this->mot_de_passe = password_hash($password, PASSWORD_DEFAULT);
        $this->poste = $poste;
        $this->date_embauche = $date_embauche;
    }

    // Enregistrement du nouvel utilisateur (create)
    public function save() {
        try {
            global $conn;
            // Optionnel : vérifier si email existe déjà
            $existing = self::findByEmail($this->email);
            if ($existing) {
                throw new Exception("Email déjà utilisé.");
            }

            $stmt = $conn->prepare("INSERT INTO registre (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, poste, date_embauche, role) 
                VALUES (:nom, :prenom, :date_naissance, :email, :numero_telephone, :mot_de_passe, :poste, :date_embauche, :role)");
            return $stmt->execute([
                ':nom' => $this->nom,
                ':prenom' => $this->prenom,
                ':date_naissance' => $this->date_naissance,
                ':email' => $this->email,
                ':numero_telephone' => $this->numero_telephone,
                ':mot_de_passe' => $this->mot_de_passe,
                ':poste' => $this->poste,
                ':date_embauche' => $this->date_embauche,
                ':role' => $this->role
            ]);
        } catch (Exception $e) {
            error_log("Erreur d'enregistrement : " . $e->getMessage());
            return false;
        }
    }

    // Récupération d’un seul utilisateur par ID (READ)
    public static function getUserById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM registre WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Récupération de tous les utilisateurs (READ)
    public static function getAllUsers() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM registre");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Suppression d'un utilisateur (DELETE)
    public static function delete($id) {
        global $conn;
        $stmt = $conn->prepare("DELETE FROM registre WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // Modification (UPDATE)
    public static function update($id, $data) {
        global $conn;
        try {
            // Récupérer ancien mot de passe si mot_de_passe non fourni
            if (empty($data['mot_de_passe'])) {
                $ancien = self::getUserById($id);
                if (!$ancien) {
                    throw new Exception("Utilisateur non trouvé.");
                }
                $mot_de_passe = $ancien['mot_de_passe'];
            } else {
                $mot_de_passe = password_hash($data['mot_de_passe'], PASSWORD_DEFAULT);
            }

            $stmt = $conn->prepare("UPDATE registre SET 
                nom = :nom, 
                prenom = :prenom, 
                date_naissance = :date_naissance,
                email = :email, 
                numero_telephone = :numero_telephone, 
                mot_de_passe = :mot_de_passe,
                poste = :poste, 
                date_embauche = :date_embauche, 
                role = :role 
                WHERE id = :id");

            return $stmt->execute([
                ':id' => $id,
                ':nom' => $data['nom'],
                ':prenom' => $data['prenom'],
                ':date_naissance' => $data['date_naissance'],
                ':email' => $data['email'],
                ':numero_telephone' => $data['numero_telephone'],
                ':mot_de_passe' => $mot_de_passe,
                ':poste' => $data['poste'],
                ':date_embauche' => $data['date_embauche'],
                ':role' => $data['role']
            ]);
        } catch (Exception $e) {
            error_log("Erreur lors de la mise à jour : " . $e->getMessage());
            return false;
        }
    }

    // Obtenir tous les postes distincts
    public static function getAllPostes() {
        global $conn;
        $stmt = $conn->query("SELECT DISTINCT poste FROM registre");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Obtenir utilisateurs par poste
    public static function getUsersByPoste($poste) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM registre WHERE poste = :poste");
        $stmt->execute(['poste' => $poste]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Trouver utilisateur par email
    public static function findByEmail($email) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT r.*, e.poste 
            FROM registre r
            LEFT JOIN emplois e ON r.id = e.employe_id  -- adapte 'employe_id' selon ta clé étrangère
            WHERE r.email = :email
            LIMIT 1
        ");
        $stmt->execute(['email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
}
?>

