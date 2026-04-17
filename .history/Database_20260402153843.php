<?php
// model.php ou Database.php

class Database {
    private static $host     = "localhost";
    private static $db_name  = "gestion_utilisateurs";
    private static $username = "root";
    private static $password = "";
    private static $conn;

    public static function connect() {
        if (self::$conn === null) {
            try {
                self::$conn = new PDO(
                    "mysql:host=" . self::$host . ";dbname=" . self::$db_name . ";charset=utf8mb4",
                    self::$username,
                    self::$password,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]
                );
                error_log("✅ Connexion à la base de données établie");
            } catch (PDOException $e) {
                error_log("❌ ERREUR DB: " . $e->getMessage());
                throw new Exception("Échec de la connexion à la base de données");
            }
        }
        return self::$conn;
    }

    public static function close() {
        self::$conn = null;
    }
}

// ====================== CLASSE USER ======================
class User {

    /**
     * Trouve un utilisateur par email dans la table employes
     */
    public static function findByEmail($email) {
        try {
            $conn = Database::connect();

            $sql = "SELECT id, nom, prenom, date_naissance, email, numero_telephone, 
                           mot_de_passe, photo_profil, poste, fid_code, statut, 
                           date_embauche, created_at, role 
                    FROM employes 
                    WHERE email = :email 
                    LIMIT 1";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();

            $user = $stmt->fetch();

            if ($user) {
                error_log("Utilisateur trouvé : " . $user['email']);
            } else {
                error_log("Aucun utilisateur trouvé avec l'email : " . $email);
            }

            return $user ?: null;

        } catch (PDOException $e) {
            error_log("Erreur findByEmail : " . $e->getMessage());
            return null;
        }
    }

    /**
     * Récupère tous les utilisateurs (utile pour l'admin)
     */
    public static function getAllUsers() {
        try {
            $conn = Database::connect();

            $sql = "SELECT id, nom, prenom, email, poste, role, statut, date_embauche 
                    FROM employes 
                    ORDER BY nom, prenom";

            $stmt = $conn->prepare($sql);
            $stmt->execute();

            return $stmt->fetchAll();

        } catch (PDOException $e) {
            error_log("Erreur getAllUsers : " . $e->getMessage());
            return [];
        }
    }

    /**
     * Récupère les utilisateurs par poste
     */
    public static function getUsersByPoste($poste) {
        try {
            $conn = Database::connect();

            $sql = "SELECT id, nom, prenom, email, poste, role, statut 
                    FROM employes 
                    WHERE poste = :poste 
                    ORDER BY nom";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':poste', $poste, PDO::PARAM_STR);
            $stmt->execute();

            return $stmt->fetchAll();

        } catch (PDOException $e) {
            error_log("Erreur getUsersByPoste : " . $e->getMessage());
            return [];
        }
    }
}
?>