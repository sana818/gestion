<?php
/**
 * uploadProfilePic.php
 * Upload photo de profil — directeur, responsable_rh, employe
 * Stockage binaire (BLOB) dans registre.photo_profil
 */

require_once __DIR__ . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

/* ══ JWT — tous les rôles autorisés ══ */
$headers    = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authorization header manquant']);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token JWT manquant ou mal formé']);
    exit;
}

$secret_key = 'Votre_Cle_Secrete_Complexe_Ici_123!@#';

try {
    $decoded = JWT::decode($matches[1], new Key($secret_key, 'HS256'));
    $userId  = $decoded->id ?? null;
    $role    = strtolower(trim($decoded->role ?? ''));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token invalide : ' . $e->getMessage()]);
    exit;
}

/* vérifier que le rôle est connu */
$rolesAutorises = ['employe', 'responsable_rh', 'directeur', 'admin'];
if (!in_array($role, $rolesAutorises)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Rôle non autorisé : ' . $role]);
    exit;
}

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ID utilisateur introuvable dans le token']);
    exit;
}

/* ══ Traitement du fichier ══ */
try {

    if (empty($_FILES['photo'])) {
        throw new Exception('Aucun fichier uploadé');
    }

    $photo = $_FILES['photo'];

    if ($photo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Erreur lors de l\'upload (code ' . $photo['error'] . ')');
    }

    /* type MIME réel (pas celui déclaré par le navigateur) */
    $mime = mime_content_type($photo['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
        throw new Exception('Format non supporté. Utilisez JPEG, PNG ou GIF.');
    }

    /* taille max 2 Mo */
    if ($photo['size'] > 2 * 1024 * 1024) {
        throw new Exception('Fichier trop volumineux (max 2 Mo)');
    }

    /* lire le binaire */
    $photoData = file_get_contents($photo['tmp_name']);
    if ($photoData === false) {
        throw new Exception('Impossible de lire le fichier');
    }

    /* enregistrer dans registre.photo_profil (BLOB) */
    $pdo = new PDO(
        'mysql:host=localhost;dbname=gestion_utilisateurs;charset=utf8',
        'root', ''
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("UPDATE registre SET photo_profil = ? WHERE id = ?");
    $stmt->execute([$photoData, $userId]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Utilisateur introuvable (id=' . $userId . ')');
    }

    /* retourner la data-URL pour affichage immédiat côté client */
    echo json_encode([
        'success'   => true,
        'message'   => 'Photo mise à jour avec succès',
        'photo_url' => 'data:' . $mime . ';base64,' . base64_encode($photoData)
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}