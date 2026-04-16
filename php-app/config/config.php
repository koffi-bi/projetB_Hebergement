<?php
// projetb — Configuration globale (compatible Render + local)
// Fichier : config/config.php

// ============================================================
//  Variables d'environnement (Render) avec fallback pour local
// ============================================================
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_NAME') ?: 'projetb';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASSWORD') ?: '';
$db_charset = 'utf8mb4';

// URLs des microservices (à définir sur Render après déploiement)
define('MATCHING_API_URL', getenv('MATCHING_API_URL') ?: 'http://localhost:5001');
define('FACE_ID_API_URL',  getenv('FACE_ID_API_URL')  ?: 'http://localhost:5000');

// URL publique du site (utilisée pour les emails, redirections)
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');

// ---- Chemins des dossiers -----------------------------------
define('UPLOAD_CV_DIR',     __DIR__ . '/../uploads/cv/');
define('UPLOAD_PHOTO_DIR',  __DIR__ . '/../uploads/photos/');

// ---- Sécurité -----------------------------------------------
define('SESSION_DUREE', 3600);  // 1 heure

// ============================================================
//  Connexion PDO — retourne un objet PDO
// ============================================================
function connexionDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $db   = getenv('DB_NAME') ?: 'projetb';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // En production, logger l'erreur sans l'afficher
            error_log('DB connection error: ' . $e->getMessage());
            die(json_encode(['erreur' => 'Connexion base de données impossible.']));
        }
    }
    return $pdo;
}

// ============================================================
//  Démarrage sécurisé des sessions (HTTPS automatique)
// ============================================================
function demarrerSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => SESSION_DUREE,
            'path'     => '/',
            'secure'   => $isHttps,   // true sur Render (HTTPS)
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

// ============================================================
//  Redirection sécurisée
// ============================================================
function rediriger(string $url): void {
    header("Location: $url");
    exit;
}

// ============================================================
//  Nettoyage anti-XSS
// ============================================================
function nettoyer(string $valeur): string {
    return htmlspecialchars(trim($valeur), ENT_QUOTES, 'UTF-8');
}