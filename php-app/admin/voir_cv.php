<?php
// voir_cv.php
require_once '../config/config.php';
require_once '../config/admin_auth.php';
demarrerSession();

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$cv_id = $_GET['id'] ?? 0;
if (!$cv_id) {
    die('ID CV manquant');
}

$pdo = connexionDB();
$stmt = $pdo->prepare("
    SELECT c.*, cv.nom_fichier, cv.chemin 
    FROM candidats c 
    LEFT JOIN cv ON cv.candidat_id = c.id 
    WHERE c.id = ?
");
$stmt->execute([$cv_id]);
$candidat = $stmt->fetch();

if (!$candidat) {
    die('Candidat non trouvé');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>CV de <?= htmlspecialchars($candidat['prenom'] . ' ' . $candidat['nom']) ?></title>
    <style>
        body { font-family: Arial; padding: 20px; box-shadow: 2 px solid black;}
        .cv-container { max-width: 800px; margin: 0 auto; }
        .card { border: 1px solid #ddd; padding: 20px; border-radius: 10px; }
        h1 { color: #333; }
        .info { margin: 10px 0; }
        .label { font-weight: bold; color: #667eea; }
    </style>
</head>
<body>
    <div class="cv-container">
        <div class="card">
            <h1>📄 CV de <?= htmlspecialchars($candidat['prenom'] . ' ' . $candidat['nom']) ?></h1>
            
            <div class="info"><span class="label">📧 Email :</span> <?= htmlspecialchars($candidat['email'] ?? 'Non renseigné') ?></div>
            <div class="info"><span class="label">📍 Ville :</span> <?= htmlspecialchars($candidat['ville'] ?? 'Non renseignée') ?></div>
            <div class="info"><span class="label">💼 Poste :</span> <?= htmlspecialchars($candidat['poste_actuel'] ?? 'Non renseigné') ?></div>
            <div class="info"><span class="label">📅 Expérience :</span> <?= $candidat['experience_ans'] ?? 'Non renseignée' ?> ans</div>
            <div class="info"><span class="label">⚡ Compétences :</span> <?= nl2br(htmlspecialchars($candidat['competences'] ?? 'Non renseignées')) ?></div>
            
            <?php if ($candidat['chemin'] && file_exists($candidat['chemin'])): ?>
                <div style="margin-top: 20px;">
                    <a href="<?= $candidat['chemin'] ?>" target="_blank" class="btn" style="background:#667eea;color:white;padding:10px 20px;text-decoration:none;border-radius:5px;">
                        📄 Télécharger le CV original
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>