<?php

require_once '../config/config.php';
demarrerSession();

// Détruire toutes les données de session
$_SESSION = [];
session_destroy();

// Rediriger vers la page d'accueil
header("Location: ../index.php");
exit;
