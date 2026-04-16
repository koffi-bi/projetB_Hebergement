<?php

require_once '../config/config.php';
require_once '../config/admin_auth.php';
demarrerSession();

header('Content-Type: application/json');

// Vérifier que l'admin est connecté
if (empty($_SESSION['admin_id'])) {
  echo json_encode(['ok' => false, 'message' => 'Non authentifié']);
  exit;
}

// Lire le JSON entrant
$donnees = json_decode(file_get_contents('php://input'), true);
$requete = trim($donnees['requete'] ?? '');

if (empty($requete)) {
  echo json_encode(['ok' => false]);
  exit;
}

try {
  $pdo = connexionDB();
  $pdo->prepare("
    INSERT INTO recherches_admin (admin_id, requete_texte)
    VALUES (?, ?)
  ")->execute([$_SESSION['admin_id'], $requete]);

  echo json_encode(['ok' => true]);
} catch (Exception $e) {
  echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
