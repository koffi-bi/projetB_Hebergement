<?php

require_once '../config/config.php';
require_once '../config/admin_auth.php';
demarrerSession();

if (!empty($_SESSION['admin_id'])) { rediriger('dashboard.php'); }

$erreur  = '';
$email_v = '';
$succes  = isset($_GET['inscription']) && $_GET['inscription'] === 'ok'
         ? 'Compte créé avec succès ! Connectez-vous maintenant.' : '';

// Option A : Connexion email + mot de passe 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['connexion_classique'])) {
  $email   = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
  $mdp     = $_POST['mot_de_passe'] ?? '';
  $email_v = $email;

  if (empty($email) || empty($mdp)) {
    $erreur = 'Veuillez remplir tous les champs.';
  } else {
    $pdo  = connexionDB();
    $stmt = $pdo->prepare("SELECT * FROM administrateurs WHERE email = ? AND statut = 'actif'");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($mdp, $admin['mot_de_passe'])) {
      $_SESSION['admin_id']  = $admin['id'];
      $_SESSION['admin_nom'] = $admin['prenom'] . ' ' . $admin['nom'];
      $pdo->prepare("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = ?")
          ->execute([$admin['id']]);
      rediriger('dashboard.php');
    } else {
      $erreur = 'Email ou mot de passe incorrect.';
    }
  }
}

// Option B : Validation du token Face ID (appelé par JS via AJAX)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['face_token'])) {
  header('Content-Type: application/json');

  $token = trim($_POST['face_token']);
  $pdo   = connexionDB();

  // Vérifier le token : doit exister, être valide et ne pas être expiré
  $stmt = $pdo->prepare("
    SELECT s.*, a.prenom, a.nom
    FROM sessions_face_id s
    JOIN administrateurs a ON a.id = s.admin_id
    WHERE s.token = ? AND s.statut = 'valide' AND s.expire_a > NOW()
  ");
  $stmt->execute([$token]);
  $session = $stmt->fetch();

  if ($session) {
    // Consommer le token (usage unique)
    $pdo->prepare("UPDATE sessions_face_id SET statut = 'consomme' WHERE token = ?")
        ->execute([$token]);

    // Créer la session PHP
    $_SESSION['admin_id']  = $session['admin_id'];
    $_SESSION['admin_nom'] = $session['prenom'] . ' ' . $session['nom'];
    $pdo->prepare("UPDATE administrateurs SET derniere_connexion = NOW() WHERE id = ?")
        ->execute([$session['admin_id']]);

    echo json_encode(['success' => true, 'redirect' => 'dashboard.php']);
  } else {
    echo json_encode(['success' => false, 'message' => 'Token Face ID invalide ou expiré.']);
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion Recruteur — CvMatchIA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="login.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  
</head>
<body>

<div class="login-carte">

  <!-- Logo -->
  <a href="../index.php" class="logo-lien">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    CvMatch<span>IA</span>
  </a>

  <!-- Badge -->
  <div class="badge-admin">
    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Accès Administrateur
  </div>

  <h1 class="login-titre">Connexion Recruteur</h1>
  <p class="login-sous-titre">Tableau de bord SmartRecruit</p>

  <?php if ($erreur): ?>
    <div class="alerte alerte-erreur">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($erreur) ?>
    </div>
  <?php endif; ?>
  <?php if ($succes): ?>
    <div class="alerte alerte-succes">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
      <?= htmlspecialchars($succes) ?>
    </div>
  <?php endif; ?>

  <!-- Onglets de choix de méthode -->
  <div class="onglets">
    <button class="onglet actif" id="onglet-classique" onclick="changerOnglet('classique')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Mot de passe
    </button>
    <button class="onglet" id="onglet-faceid" onclick="changerOnglet('faceid')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><circle cx="12" cy="12" r="4"/></svg>
      Face ID
    </button>
  </div>

  <!-- ── Panneau 1 : Mot de passe ── -->
  <div class="panneau actif" id="panneau-classique">
    <form method="POST" action="login.php" novalidate>
      <input type="hidden" name="connexion_classique" value="1">

      <div class="champ">
        <label for="email">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          Email professionnel
        </label>
        <input type="email" id="email" name="email" placeholder="k.mensah@entreprise.ci"
          value="<?= htmlspecialchars($email_v) ?>" required autofocus>
      </div>

      <div class="champ">
        <label for="mot_de_passe">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Mot de passe
        </label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" placeholder="••••••••" required>
      </div>

      <div class="login-options">
        <a href="#" class="oublie">Mot de passe oublié ?</a>
      </div>

      <button type="submit" class="btn-principal">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        Accéder au dashboard
      </button>
    </form>
  </div>

  <!-- Panneau 2 : Face ID  -->
  <div class="panneau" id="panneau-faceid">
    <div class="faceid-zone">

      <!-- Cercle animé avec icône SVG bleue à l'intérieur -->
      <div class="faceid-scanner" id="faceid-scanner">
        <div class="scan-ligne"></div>
        <!-- Conteneur de l'icône — changé dynamiquement par face_id.js -->
        <div class="faceid-icone" id="faceid-icone">
          <!-- Icône "visage" par défaut -->
          <svg width="52" height="52" viewBox="0 0 24 24" fill="none"
               stroke="#1D6FEB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </div>
      </div>

      <p class="faceid-label">
        Placez votre visage dans le cadre<br>
        et cliquez sur <strong>Scanner</strong>.
      </p>

      <!-- Zone de statut — remplie par face_id.js -->
      <div class="faceid-statut" id="faceid-statut"></div>

      <!-- Bouton qui déclenche lancerFaceID() dans face_id.js -->
      <button class="btn-scanner" id="btn-scanner" onclick="lancerFaceID()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#78AAFF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/>
          <path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
          <circle cx="12" cy="12" r="4"/>
        </svg>
        Scanner mon visage
      </button>

    </div>
  </div>

  <p class="lien-alternatif">
    Pas encore de compte ? <a href="register.php">Créer un compte recruteur</a>
  </p>
  <a href="../index.php" class="retour">← Retour à l'accueil</a>

</div>

<script src="login.js"></script>
<!-- Script Face ID partagé (gère lancerFaceID + setIcone + afficherStatut) -->
<script src="face_id.js"></script>

</body>
</html>
