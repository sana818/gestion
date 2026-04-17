<?php
require_once(__DIR__ . '/atabase.php');

class User {

    // 🔹 Trouver par email (LOGIN)
    public static function findByEmail($email) {
        global $conn;

        $stmt = $conn->prepare("SELECT * FROM employes WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 🔹 Trouver par ID
    public static function getUserById($id) {
        global $conn;

        $stmt = $conn->prepare("SELECT * FROM employes WHERE id = ?");
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 🔹 Tous les utilisateurs
    public static function getAllUsers() {
        global $conn;

        $stmt = $conn->query("SELECT * FROM employes ORDER BY created_at DESC");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 🔹 Utilisateurs actifs
    public static function getAllUsersActifs() {
        global $conn;

        $stmt = $conn->query("SELECT * FROM employes WHERE statut = 'actif' ORDER BY nom ASC");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 🔹 En attente
    public static function getEnAttente() {
        global $conn;

        $stmt = $conn->query("SELECT * FROM employes WHERE statut = 'en_attente' ORDER BY created_at DESC");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 🔹 Supprimer
    public static function delete($id) {
        global $conn;

        $stmt = $conn->prepare("DELETE FROM employes WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // 🔹 Mettre à jour statut
    public static function updateStatut($id, $statut) {
        global $conn;

        $stmt = $conn->prepare("UPDATE employes SET statut = :statut WHERE id = :id");

        return $stmt->execute([
            ':statut' => $statut,
            ':id' => $id
        ]);
    }

    // 🔹 Trouver par FID
    public static function findByFidCode($fid_code) {
        global $conn;

        $stmt = $conn->prepare("SELECT * FROM employes WHERE fid_code = :fid_code LIMIT 1");
        $stmt->execute([':fid_code' => $fid_code]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>