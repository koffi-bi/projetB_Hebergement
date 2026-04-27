<?php
// projetb — Configuration globale
// Fichier : config/config.php

// ============================================================
//  Base de données (Aiven MySQL sur Render)
// ============================================================
define('DB_HOST',     getenv('DB_HOST') ?: 'localhost');
define('DB_PORT',     getenv('DB_PORT') ?: '3306');
define('DB_NAME',     getenv('DB_NAME') ?: 'projetb');
define('DB_USER',     getenv('DB_USER') ?: 'root');
define('DB_PASS',     getenv('DB_PASSWORD') ?: '');
define('DB_CHARSET',  'utf8mb4');

// ============================================================
//  Microservice Python (Matching Service sur Render)
// ============================================================
// Sur Render, utilisez le nom du service interne ou l'URL publique
define('PYTHON_API_URL', getenv('PYTHON_API_URL') ?: 'https://matching-service-fsdd.onrender.com');

// ============================================================
//  Cloudinary - Remplissez avec VOS vraies valeurs !
// ============================================================
define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME') ?: 'dvd3bmsri');
define('CLOUDINARY_API_KEY',    getenv('CLOUDINARY_API_KEY') ?: '851383611145189');
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET') ?: 'nqL_JWjXSwgEKToL3DyKyRP94rA');

// ============================================================
//  Chemins des dossiers (Render ou local)
// ============================================================
define('UPLOAD_CV_DIR',     __DIR__ . '/../uploads/cv/');
define('UPLOAD_PHOTO_DIR',  __DIR__ . '/../uploads/photos/');

// ============================================================
//  Sécurité
// ============================================================
define('SESSION_DUREE', 3600);  // 1 heure

// ============================================================
//  Connexion PDO
// ============================================================
function connexionDB(): PDO {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST
             . ";dbname=" . DB_NAME
             . ";charset=" . DB_CHARSET;
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("DB Error: " . $e->getMessage());
            die(json_encode(['erreur' => 'Connexion base de données impossible.']));
        }
    }
    return $pdo;
}

// ============================================================
//  Démarrage sécurisé des sessions
// ============================================================
function demarrerSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_DUREE,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']), // Auto-détection HTTPS
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
//  Nettoyage des entrées utilisateur
// ============================================================
function nettoyer(string $valeur): string {
    return htmlspecialchars(trim($valeur), ENT_QUOTES, 'UTF-8');
}
?>
