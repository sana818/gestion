<?php
class Database {
    private static $host = "localhost";
    private static $db_name = "gestion_utilisateurs";
    private static $username = "root";
    private static $password = "";
    private static $conn;

    public static function connect() {
        if (self::$conn === null) {
            try {
                self::$conn = new PDO(
                    "mysql:host=" . self::$host . ";dbname=" . self::$db_name . ";charset=utf8",
                    self::$username,
                    self::$password
                );
                self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                error_log("Connexion à la base de données établie");
            } catch (PDOException $e) {
                error_log("ERREUR DB: " . $e->getMessage());
                throw new Exception("Échec de la connexion à la base de données");
            }
        }
        return self::$conn;
    }

    public static function findByEmail($email) {
        try {
            $conn = self::connect();
            $stmt = $conn->prepare("SELECT * FROM registre WHERE email = :email");
            $stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $stmt->execute();
            $user = $stmt->fetch();
            if ($user) {
                error_log("Utilisateur trouvé: " . $user['email']);
            } else {
                error_log("Aucun utilisateur trouvé avec cet email");
            }
            return $user ?: null; // null si pas trouvé
        } catch (PDOException $e) {
            error_log("Erreur dans findByEmail: " . $e->getMessage());
            return false;
        }
    }

    public static function close() {
        self::$conn = null;
    }
    //get all users
    public static function getAllUsers() {
        try {
            $conn = self::connect();
            $stmt = $conn->prepare("SELECT id, nom, email, role FROM registre");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erreur dans getAllUsers: " . $e->getMessage());
            return [];
        }
    }
    public static function getUsersByPoste($poste) {
        global $conn;  // si ta connexion est dans une variable globale $conn
        $stmt = $conn->prepare("SELECT * FROM registre WHERE poste = :poste");
        $stmt->execute(['poste' => $poste]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
// Test de connexion (à retirer en production)
try {
    $conn = Database::connect();
} catch (Exception $e) {
    die("ERREUR: " . $e->getMessage());
}
?>