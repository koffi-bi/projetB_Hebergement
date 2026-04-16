<?php
session_start();

function checkAdminAuth($pdo) {
    // On utilise une clé spécifique 'admin_id'
    if (!isset($_SESSION['admin_id'])) {
        header("Location: login_admin.php");
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM administrateurs WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin) {
        // Destruction complète si l'admin n'existe plus
        $_SESSION = array();
        session_destroy();
        header("Location: login_admin.php");
        exit;
    }
}