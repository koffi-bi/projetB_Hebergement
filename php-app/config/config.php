<?php
//projetb — Configuration globale
//  Fichier : config/config.php

//  Base de données 
define('DB_HOST',     'localhost');
define('DB_NAME',     'projetb');
define('DB_USER',     'root');        
define('DB_PASS',     '');            
define('DB_CHARSET',  'utf8mb4');

// Microservice Python (Face ID + IA) 
define('PYTHON_API_URL',  'http://localhost:5000');  // URL du serveur Flask

// ---- Chemins des dossiers -----------------------------------
define('UPLOAD_CV_DIR',     __DIR__ . '/../uploads/cv/');
define('UPLOAD_PHOTO_DIR',  __DIR__ . '/../uploads/photos/');

// ---- Sécurité -----------------------------------------------
define('SESSION_DUREE',   3600);  // 1 heure en secondes

// ============================================================
//  Connexion PDO — retourne un objet PDO prêt à l'emploi
//  Utilisation : $pdo = connexionDB();
// ============================================================
function connexionDB(): PDO {
  static $pdo = null;            // singleton : une seule connexion par requête

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
      // En production : logger l'erreur, ne pas afficher les détails
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
      'secure'   => false,   // passer à true avec HTTPS en production
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
//  Nettoyage des entrées utilisateur (anti-XSS)
// ============================================================
function nettoyer(string $valeur): string {
  return htmlspecialchars(trim($valeur), ENT_QUOTES, 'UTF-8');
}

// Configuration Cloudinary
define('CLOUDINARY_CLOUD_NAME', 'dvd3bmsri'); // À récupérer sur cloudinary.com
define('CLOUDINARY_API_KEY', '851383611145189');
define('CLOUDINARY_API_SECRET', 'nqL_JWjXSwgEKToL3DyKyRP94rA');