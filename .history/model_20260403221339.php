<?php
if (class_exists('User')) return;

class User {
    public static function findByEmail($email) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getUserById($id) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function getAllUsers() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM employes ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getAllUsersActifs() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM employes WHERE statut = 'actif' ORDER BY nom ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getEnAttente() {
        global $conn;
        $stmt = $conn->query("SELECT * FROM employes WHERE statut = 'en_attente' ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function save($data) {
        global $conn;
        $existing = self::findByEmail($data['email']);
        if ($existing) throw new Exception("Email deja utilise.");

        $stmt = $conn->prepare("
            INSERT INTO employes 
                (nom, prenom, date_naissance, email, numero_telephone, mot_de_passe, poste, date_embauche, role, statut) 
            VALUES 
                (:nom, :prenom, :date_naissance, :email, :numero_telephone, :mot_de_passe, :poste, :date_embauche, :role, :statut)
        ");
        return $stmt->execute([
            ':nom'              => $data['nom'],
            ':prenom'           => $data['prenom'],
            ':date_naissance'   => $data['date_naissance'],
            ':email'            => $data['email'],
            ':numero_telephone' => $data['numero_telephone'],
            ':mot_de_passe'     => password_hash($data['mot_de_passe'], PASSWORD_DEFAULT),
            ':poste'            => $data['poste'],
            ':date_embauche'    => $data['date_embauche'],
            ':role'             => $data['role'],
            ':statut'           => 'en_attente'
        ]);
    }

    public static function update($id, $data) {
        global $conn;
        if (empty($data['mot_de_passe'])) {
            $ancien = self::getUserById($id);
            $mot_de_passe = $ancien['mot_de_passe'];
        } else {
            $mot_de_passe = password_hash($data['mot_de_passe'], PASSWORD_DEFAULT);
        }
        $stmt = $conn->prepare("
            UPDATE employes SET
                nom = :nom, prenom = :prenom, date_naissance = :date_naissance,
                email = :email, numero_telephone = :numero_telephone,
                mot_de_passe = :mot_de_passe, poste = :poste,
                date_embauche = :date_embauche, role = :role, statut = :statut
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
    }

    public static function updateStatut($id, $statut) {
        global $conn;
        $stmt = $conn->prepare("UPDATE employes SET statut = :statut WHERE id = :id");
        return $stmt->execute([':statut' => $statut, ':id' => $id]);
    }

    public static function updatePhoto($id, $chemin_photo) {
        global $conn;
        $stmt = $conn->prepare("UPDATE employes SET photo_profil = :photo WHERE id = :id");
        return $stmt->execute([':photo' => $chemin_photo, ':id' => $id]);
    }

    public static function delete($id) {
        global $conn;
        $stmt = $conn->prepare("DELETE FROM employes WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function getAllPostes() {
        global $conn;
        $stmt = $conn->query("SELECT DISTINCT poste FROM employes WHERE poste IS NOT NULL");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function getUsersByPoste($poste) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE poste = :poste");
        $stmt->execute([':poste' => $poste]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findByFidCode($fid_code) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM employes WHERE fid_code = :fid_code LIMIT 1");
        $stmt->execute([':fid_code' => $fid_code]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}