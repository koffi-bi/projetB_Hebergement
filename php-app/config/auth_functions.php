<?php
session_start();
require_once '../config/config.php';

function checkAuth($pdo) {
    // 1. Si la variable de session n'existe même pas, on dégage direct
    if (!isset($_SESSION['candidat_id'])) {
        header("Location: inscription.php");
        exit;
    }

    // 2. Vérifier si l'ID en session correspond toujours à un candidat réel
    $stmt = $pdo->prepare("SELECT id FROM candidats WHERE id = ?");
    $stmt->execute([$_SESSION['candidat_id']]);
    $candidat = $stmt->fetch();

    // 3. SI LE COMPTE N'EXISTE PLUS (Supprimé de la BDD)
    if (!$candidat) {
        
        // --- DESTRUCTION TOTALE DE LA SESSION ---
        
        // On vide toutes les variables de session
        $_SESSION = array();

        // On détruit le cookie de session dans le navigateur de l'utilisateur
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        // On détruit enfin la session sur le serveur
        session_destroy();

        // Redirection vers l'inscription ou la connexion
        header("Location: inscription.php?error=compte_inexistant");
        exit;
    }
}