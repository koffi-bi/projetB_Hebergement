<?php
// ================================================================
//  SmartRecruit — Chargeur de variables .env
//  Fichier : config/env_loader.php
//
//  Lit le fichier .env à la racine du projet et rend les variables
//  disponibles via getenv('NOM_VARIABLE').
//
//  Usage dans dashboard.php :
//      require_once '../config/env_loader.php';
//      $email = getenv('MAIL_FROM');
// ================================================================

function chargerEnv(string $chemin): void
{
    // Si le fichier .env n'existe pas, on continue sans erreur
    if (!file_exists($chemin)) {
        return;
    }

    $lignes = file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lignes as $ligne) {
        // Ignorer les commentaires (lignes commençant par #)
        $ligne = trim($ligne);
        if (str_starts_with($ligne, '#') || $ligne === '') {
            continue;
        }

        // Séparer NOM=VALEUR
        if (!str_contains($ligne, '=')) {
            continue;
        }

        [$nom, $valeur] = explode('=', $ligne, 2);
        $nom    = trim($nom);
        $valeur = trim($valeur);

        // Retirer les guillemets optionnels autour de la valeur
        if (str_starts_with($valeur, '"') && str_ends_with($valeur, '"')) {
            $valeur = substr($valeur, 1, -1);
        }

        // Mettre la variable dans l'environnement PHP
        if (!getenv($nom)) {
            putenv("$nom=$valeur");
            $_ENV[$nom] = $valeur;
        }
    }
}

// Chargement automatique dès que ce fichier est inclus
chargerEnv(dirname(__DIR__) . '/.env');
