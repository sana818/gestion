<?php
class Database {
    private static $host     = "localhost";
    private static $db_name  = "gestion_utilisateurs";
    private static $username = "root";
    private static $password = "";
    private static $conn     = null;

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
            } catch (PDOException $e) {
                error_log("ERREUR DB: " . $e->getMessage());
                // Lance une exception au lieu de die()
                throw new Exception("Échec de la connexion à la base de données: " . $e->getMessage());
            }
        }
        return self::$conn;
    }

    public static function close() {
        self::$conn = null;
    }
}

// Initialiser $conn comme variable globale pour model.php
// Les deux systèmes sont ainsi compatibles
try {
    $conn = Database::connect();
} catch (Exception $e) {
    // Ne jamais utiliser die() dans une API — retourner du JSON
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Connexion base de données impossible: ' . $e->getMessage()
    ]);
    exit();
}
?>