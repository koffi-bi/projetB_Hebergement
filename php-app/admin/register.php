<?php
// ================================================================
//  SmartRecruit — Inscription Administrateur
//  Fichier : admin/register.php
//
//  FLUX :
//  1. L'admin remplit le formulaire (prénom, nom, email, mdp)
//  2. Il clique "Activer Face ID" → JS appelle Python /enregistrer-visage
//     Python ouvre la webcam, capture le visage, renvoie l'encoding (128 floats)
//  3. L'encoding est stocké dans le champ caché #face_encoding_input
//  4. Soumission du formulaire → PHP insère tout en base MySQL
// ================================================================
require_once '../config/config.php';
require_once '../config/admin_auth.php';
demarrerSession();

if (!empty($_SESSION['admin_id'])) { rediriger('dashboard.php'); }

$erreurs  = [];
$ancienne = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $prenom        = nettoyer($_POST['prenom']        ?? '');
  $nom           = nettoyer($_POST['nom']           ?? '');
  $email         = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
  $mdp           = $_POST['mot_de_passe']           ?? '';
  $mdp2          = $_POST['mdp_confirm']            ?? '';
  $face_encoding = $_POST['face_encoding']          ?? '';
  $face_id_actif = !empty($face_encoding) ? 1 : 0;
  $ancienne      = compact('prenom','nom','email');

  if (empty($prenom))  $erreurs[] = 'Le prénom est obligatoire.';
  if (empty($nom))     $erreurs[] = 'Le nom est obligatoire.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erreurs[] = 'Adresse email invalide.';
  if (strlen($mdp) < 8) $erreurs[] = 'Mot de passe trop court (min. 8 caractères).';
  if ($mdp !== $mdp2)   $erreurs[] = 'Les deux mots de passe ne correspondent pas.';

  if (empty($erreurs)) {
    $pdo  = connexionDB();
    $stmt = $pdo->prepare("SELECT id FROM administrateurs WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) $erreurs[] = 'Cet email est déjà utilisé. <a href="login.php">Se connecter ?</a>';
  }

  if (empty($erreurs)) {
    $hash = password_hash($mdp, PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO administrateurs (prenom, nom, email, mot_de_passe, face_encoding, face_id_actif) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$prenom, $nom, $email, $hash, $face_encoding, $face_id_actif]);
    $_SESSION['admin_id']  = $pdo->lastInsertId();
    $_SESSION['admin_nom'] = $prenom . ' ' . $nom;
    rediriger('login.php?inscription=ok');
  }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inscription Recruteur — SmartRecruit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="register.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

    
</head>
<body>
<div class="auth-wrapper">

  <!-- PANNEAU GAUCHE -->
  <div class="auth-panneau">
    <a href="../index.php" class="auth-logo">
      <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      Smart<span>Recruit</span>
    </a>
    <div class="badge-securite">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Espace sécurisé
    </div>
    <h2 class="panneau-titre">Tableau de bord<br>Recruteur</h2>
    <p class="panneau-desc">Interface professionnelle propulsée par Groq. Recherchez, analysez et contactez les meilleurs candidats.</p>
    <ul class="avantages">
      <li><div class="ico-cercle"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><circle cx="12" cy="12" r="4"/></svg></div>Authentification biométrique Face ID</li>
      <li><div class="ico-cercle"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></div>Recherche en langage naturel (Groq)</li>
      <li><div class="ico-cercle"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>Analyse IA des CV en temps réel</li>
      <li><div class="ico-cercle"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>Classement intelligent des profils</li>
    </ul>
  </div>

  <!-- FORMULAIRE DROITE -->
  <div class="auth-formulaire">
    <h1 class="form-titre">Créer un compte recruteur</h1>
    <p class="form-sous-titre">Accès au Dashboard Administrateur</p>

    <?php if (!empty($erreurs)): ?>
      <div class="alerte-erreur"><ul><?php foreach($erreurs as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" action="register.php" novalidate>
      <div class="form-grille">

        <div class="champ">
          <label for="prenom">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Prénom *
          </label>
          <input type="text" id="prenom" name="prenom" placeholder="Kofi" value="<?= htmlspecialchars($ancienne['prenom']??'') ?>" required>
        </div>

        <div class="champ">
          <label for="nom">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Nom *
          </label>
          <input type="text" id="nom" name="nom" placeholder="Mensah" value="<?= htmlspecialchars($ancienne['nom']??'') ?>" required>
        </div>

        <div class="champ pleine-largeur">
          <label for="email">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Email professionnel *
          </label>
          <input type="email" id="email" name="email" placeholder="k.mensah@entreprise.ci" value="<?= htmlspecialchars($ancienne['email']??'') ?>" required>
        </div>

        <div class="champ">
          <label for="mot_de_passe">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Mot de passe * <span style="font-weight:400;color:#94A3B8;font-size:0.72rem">(min. 8 car.)</span>
          </label>
          <input type="password" id="mot_de_passe" name="mot_de_passe" placeholder="••••••••" required>
        </div>

        <div class="champ">
          <label for="mdp_confirm">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Confirmer le mot de passe *
          </label>
          <input type="password" id="mdp_confirm" name="mdp_confirm" placeholder="••••••••" required>
        </div>

        <!-- ── BLOC FACE ID ── -->
        <div class="faceid-bloc">
          <div class="faceid-entete">
            <div class="faceid-icone-wrap">
              <!-- Icône scan visage (cadre + yeux + bouche) = Face ID -->
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                <path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                <line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>
              </svg>
            </div>
            <div class="faceid-info">
              <h4>Authentification Face ID</h4>
              <p>Facultatif. Activez pour vous connecter par reconnaissance faciale.</p>
            </div>
          </div>

          <!-- Statut de la capture (affiché par face_id.js) -->
          <div id="faceid-statut" class="faceid-statut"></div>

          <!-- Bouton qui déclenche activerFaceID() dans face_id.js -->
          <button type="button" id="btn-faceid" class="btn-faceid" onclick="activerFaceID()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#78AAFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            Activer Face ID
          </button>

          <!-- Champ caché : PHP lit face_encoding via $_POST['face_encoding'] -->
          <input type="hidden" id="face_encoding_input" name="face_encoding" value="">
        </div>

      </div>

      <button type="submit" class="btn-principal">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        Créer mon compte recruteur
      </button>
    </form>

    <p class="lien-alternatif">Déjà un compte ? <a href="login.php">Se connecter</a></p>
    <p class="lien-alternatif" style="margin-top:8px;"><a href="../index.php" style="color:#A0AEC0;">← Retour à l'accueil</a></p>
  </div>

</div>

<!-- Script Face ID partagé (gère activerFaceID) -->
<script src="face_id.js"></script>
</body>
</html>
