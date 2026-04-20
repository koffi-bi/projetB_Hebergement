<?php
// register_face.php — Activation Face ID (après inscription)
require_once '../config/config.php';
require_once '../config/admin_auth.php';
demarrerSession();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    rediriger('login.php');
}

// Récupérer le nom de l'admin
$pdo = connexionDB();
$stmt = $pdo->prepare("SELECT prenom, nom FROM administrateurs WHERE id = ?");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch();
if (!$admin) rediriger('login.php');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Activer Face ID</title>
    <link rel="stylesheet" href="register.css">
    <style>
        .faceid-activation {
            max-width: 600px;
            margin: 50px auto;
            text-align: center;
            background: var(--blanc);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .faceid-scanner {
            width: 200px;
            height: 200px;
            margin: 20px auto;
            border-radius: 50%;
            background: var(--gris-fond);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        .btn-faceid {
            background: #1D6FEB;
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 40px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            margin-top: 15px;
        }
        .faceid-statut {
            margin: 15px 0;
            padding: 10px;
            border-radius: 12px;
        }
        .succes { background: #e6fffa; color: #276749; }
        .echec { background: #fff5f5; color: #e53e3e; }
        .attente { background: #ebf8ff; color: #2b6cb0; }
        .btn-secondaire {
            display: inline-block;
            margin-top: 15px;
            color: var(--gris-texte);
            text-decoration: none;
            font-size: 14px;
        }
    </style>
</head>
<body>
<div class="faceid-activation">
    <h1>😀 Activer Face ID</h1>
    <p>Bonjour <?= htmlspecialchars($admin['prenom'] . ' ' . $admin['nom']) ?>,<br>
    Activez la reconnaissance faciale pour vous connecter plus rapidement.</p>

    <div class="faceid-scanner" id="faceid-scanner">
        <div id="faceid-icone">
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="1.5">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
            </svg>
        </div>
    </div>

    <div id="faceid-statut" class="faceid-statut"></div>

    <button type="button" class="btn-faceid" id="btn-faceid" onclick="activerFaceID(<?= $admin_id ?>)">
        📸 Activer Face ID
    </button>
    <br>
    <a href="dashboard.php" class="btn-secondaire">⏭️ Passer (configurer plus tard)</a>
</div>

<script src="face_id.js"></script>
</body>
</html>
