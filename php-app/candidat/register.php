<?php
require_once '../config/config.php';
require_once '../config/auth_functions.php';

demarrerSession();

// Si déjà connecté → aller directement à l'espace candidat
if (!empty($_SESSION['candidat_id'])) {
  rediriger('espace.php');
}

$erreurs  = [];
$succes   = '';
$ancienne = [];   // conserve les valeurs du formulaire en cas d'erreur


//  TRAITEMENT DU FORMULAIRE (soumission POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  //  Récupération et nettoyage des champs
  $prenom    = nettoyer($_POST['prenom']    ?? '');
  $nom       = nettoyer($_POST['nom']       ?? '');
  $email     = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
  $telephone = nettoyer($_POST['telephone'] ?? '');
  $ville     = nettoyer($_POST['ville']     ?? '');
  
  $mdp       = $_POST['mot_de_passe']       ?? '';
  $mdp2      = $_POST['mdp_confirm']        ?? '';

  // Conserver pour ré-afficher en cas d'erreur
  $ancienne = compact('prenom','nom','email','telephone','ville');

  //  2-Validations
  if (empty($prenom))    $erreurs[] = 'Le prénom est obligatoire.';
  if (empty($nom))       $erreurs[] = 'Le nom est obligatoire.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erreurs[] = 'Email invalide.';
  if (strlen($mdp) < 8)  $erreurs[] = 'Le mot de passe doit faire au moins 8 caractères.';
  if ($mdp !== $mdp2)    $erreurs[] = 'Les mots de passe ne correspondent pas.';

  //  3. Vérifier si l'email existe déjà 
  if (empty($erreurs)) {
    $pdo  = connexionDB();
    $stmt = $pdo->prepare("SELECT id FROM candidats WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
      $erreurs[] = 'Cet email est déjà utilisé. <a href="login.php">Se connecter ?</a>';
    }
  }

  //  4. Insertion en base 
  if (empty($erreurs)) {
    $hash = password_hash($mdp, PASSWORD_BCRYPT);

    $insert = $pdo->prepare("
      INSERT INTO candidats (prenom, nom, email, mot_de_passe, telephone, ville)
      VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$prenom, $nom, $email, $hash, $telephone, $ville]);

    // Connexion automatique après inscription
    $_SESSION['candidat_id']  = $pdo->lastInsertId();
    $_SESSION['candidat_nom'] = $prenom . ' ' . $nom;

    // Rediriger vers l'espace candidat
    rediriger('espace.php');
  }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inscription Candidat — CvMatchIA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="register.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

 
</head>
<body>

<div class="auth-wrapper">

  <!-- PANNEAU GAUCHE -->
  <div class="auth-panneau">
    <a href="../index.php" class="auth-logo">CvMatch<span>IA</span></a>

    <h2 class="panneau-titre">Rejoignez notre<br>réseau de talents</h2>
    <p class="panneau-desc">
      Créez votre profil candidat, déposez votre CV et laissez notre
      intelligence artificielle vous mettre en relation avec les meilleures
      opportunités à Abidjan et en Côte d'Ivoire.
    </p>

    <ul class="avantages">
      <li>Profil analysé et valorisé automatiquement par l'IA</li>
      <li>Visibilité auprès de recruteurs vérifiés</li>
      <li>CV accepté en PDF, Word ou photo</li>
      <li>Historique complet de vos candidatures</li>
    </ul>
  </div>

  <!--  FORMULAIRE DROITE  -->
  <div class="auth-formulaire">

    <h1 class="form-titre">Créer un compte</h1>
    <p class="form-sous-titre">Espace Candidat — Inscription gratuite</p>

    <!-- Affichage des erreurs PHP -->
    <?php if (!empty($erreurs)): ?>
      <div class="alerte alerte-erreur">
        <ul>
          <?php foreach ($erreurs as $e): ?>
            <li><?= $e ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Formulaire POST vers ce même fichier -->
    <form method="POST" action="register.php" novalidate>

      <div class="form-grille">

        <div class="champ">
          <label for="prenom">Prénom *</label>
          <input type="text" id="prenom" name="prenom"
            placeholder="Amara"
            value="<?= htmlspecialchars($ancienne['prenom'] ?? '') ?>"
            required>
        </div>

        <div class="champ">
          <label for="nom">Nom *</label>
          <input type="text" id="nom" name="nom"
            placeholder="Koné"
            value="<?= htmlspecialchars($ancienne['nom'] ?? '') ?>"
            required>
        </div>

        <div class="champ pleine-largeur">
          <label for="email">Adresse email *</label>
          <input type="email" id="email" name="email"
            placeholder="amara.kone@email.com"
            value="<?= htmlspecialchars($ancienne['email'] ?? '') ?>"
            required>
        </div>

        <div class="champ">
          <label for="telephone">Téléphone <span class="facultatif">(facultatif)</span></label>
          <input type="tel" id="telephone" name="telephone"
            placeholder="+225 07 00 00 00"
            value="<?= htmlspecialchars($ancienne['telephone'] ?? '') ?>">
        </div>

        <div class="champ">
          <label for="ville">Ville <span class="facultatif">(facultatif)</span></label>
          <input type="text" id="ville" name="ville"
            placeholder="Abidjan"
            value="<?= htmlspecialchars($ancienne['ville'] ?? '') ?>">
        </div>

        <div class="champ">
          <label for="mot_de_passe">Mot de passe * <span class="facultatif">(min. 8 car.)</span></label>
          <input type="password" id="mot_de_passe" name="mot_de_passe"
            placeholder="••••••••" required>
        </div>

        <div class="champ">
          <label for="mdp_confirm">Confirmer le mot de passe *</label>
          <input type="password" id="mdp_confirm" name="mdp_confirm"
            placeholder="••••••••" required>
        </div>

      </div>

      <button type="submit" class="btn-principal">
        Créer mon compte →
      </button>

    </form>

    <p class="lien-alternatif">
      Déjà un compte ? <a href="login.php">Se connecter</a>
    </p>

    <p class="lien-alternatif" style="margin-top:10px;">
      <a href="../index.php" style="color:#6B7A8E;">← Retour à l'accueil</a>
    </p>

  </div>

</div>
</body>
</html>
