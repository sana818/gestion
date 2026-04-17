<?php
if (class_exists('User')) return;
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
    public $photo_profil;
    public $fid_code;
    public $statut;

    public function __construct($nom, $prenom, $date_naissance, $email, $numero_telephone, $password, $poste, $role, $date_embauche) {
        $this->nom              = $nom;
        $this->prenom           = $prenom;
        $this->date_naissance   = $date_naissance;
        $this->email            = $email;
        $this->numero_telephone = $numero_telephone;
        $this->role             = $role;
        $this->mot_de_passe     = password_hash($password, PASSWORD_DEFAULT);
        $this->poste            = $poste;
        $this->date_embauche    = $date_embauche;
        $this->statut           = 'en_attente'; // statut par défaut à l'inscription
    }

    // CREATE — enregistrer un nouvel employé
    public function save() {
        try {
            global $conn;

            $existing = self::findByEmail($this->email);
            if ($existing) {
                throw new Exception("Email déjà utilisé.");
            }

            $stmt = $conn->prepare("
                INSERT INTO employes 
                    (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, poste, date_embauche, role, statut) 
                VALUES 
                    (:nom, :prenom, :date_naissance, :email, :numero_telephone, :mot_de_passe, :poste, :date_embauche, :role, :statut)
            ");

            return $stmt->execute([
                ':nom'              => $this->nom,
                ':prenom'           => $this->prenom,
                ':date_naissance'   => $this->date_naissance,
                ':email'            => $this->email,
                ':numero_telephone' => $this->numero_telephone,
                ':mot_de_passe'     => $this->mot_de_passe,
                ':poste'            => $this->poste,
                ':date_embauche'    => $this->date_embauche,
                ':role'             => $this->role,
                ':statut'           => $this->statut
            ]);

        } catch (Exception $e) {
            error_log("Erreur save() : " . $e->getMessage());
            return false;
        }
    }

    // READ — un employé par ID
    public static function getUserById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // READ — tous les employés
    public static function getAllUsers() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM employes ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // READ — employés actifs seulement
    public static function getAllUsersActifs() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM employes WHERE statut = 'actif' ORDER BY nom ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // READ — employés en attente de validation
    public static function getEnAttente() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM employes WHERE statut = 'en_attente' ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // DELETE
    public static function delete($id) {
        global $conn;
        $stmt = $conn->prepare("DELETE FROM employes WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // UPDATE
    public static function update($id, $data) {
        global $conn;
        try {
            if (empty($data['mot_de_passe'])) {
                $ancien = self::getUserById($id);
                if (!$ancien) throw new Exception("Utilisateur non trouvé.");
                $mot_de_passe = $ancien['mot_de_passe'];
            } else {
                $mot_de_passe = password_hash($data['mot_de_passe'], PASSWORD_DEFAULT);
            }

            $stmt = $conn->prepare("
                UPDATE employes SET
                    nom              = :nom,
                    prenom           = :prenom,
                    date_naissance   = :date_naissance,
                    email            = :email,
                    numero_telephone = :numero_telephone,
                    mot_de_passe     = :mot_de_passe,
                    poste            = :poste,
                    date_embauche    = :date_embauche,
                    role             = :role,
                    statut           = :statut
                WHERE id = :id
            ");

            return $stmt->execute([
                ':id'               => $id,
                ':nom'              => $data['nom'],
                ':prenom'           => $data['prenom'],
                ':date_naissance'   => $data['date_naissance'],
                ':email'            => $data['email'],
                ':numero_telephone' => $data['numero_telephone'],
                ':mot_de_passe'     => $mot_de_passe,
                ':poste'            => $data['poste'],
                ':date_embauche'    => $data['date_embauche'],
                ':role'             => $data['role'],
                ':statut'           => $data['statut'] ?? 'en_attente'
            ]);

        } catch (Exception $e) {
            error_log("Erreur update() : " . $e->getMessage());
            return false;
        }
    }

    // UPDATE — changer le statut uniquement (actif / inactif / en_attente)
    public static function updateStatut($id, $statut) {
        global $conn;
        $stmt = $conn->prepare("UPDATE employes SET statut = :statut WHERE id = :id");
        return $stmt->execute([':statut' => $statut, ':id' => $id]);
    }

    // UPDATE — photo de profil
    public static function updatePhoto($id, $chemin_photo) {
        global $conn;
        $stmt = $conn->prepare("UPDATE employes SET photo_profil = :photo WHERE id = :id");
        return $stmt->execute([':photo' => $chemin_photo, ':id' => $id]);
    }

    // READ — postes distincts
    public static function getAllPostes() {
        global $conn;
        $stmt = $conn->query("SELECT DISTINCT poste FROM employes WHERE poste IS NOT NULL");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // READ — employés par poste
    public static function getUsersByPoste($poste) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE poste = :poste");
        $stmt->execute([':poste' => $poste]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // READ — trouver par email (utilisé pour le login)
    public static function findByEmail($email) {
        global $conn;
        $stmt = $conn->prepare("
            SELECT * FROM employes WHERE email = :email LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // READ — trouver par fid_code
    public static function findByFidCode($fid_code) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE fid_code = :fid_code LIMIT 1");
        $stmt->execute([':fid_code' => $fid_code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>