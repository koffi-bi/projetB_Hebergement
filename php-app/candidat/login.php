<?php

require_once '../config/config.php';
require_once '../config/auth_functions.php';
demarrerSession();

// Déjà connecté → espace candidat
if (!empty($_SESSION['candidat_id'])) {
  rediriger('espace.php');
}

$erreur  = '';
$email_v = '';   // valeur conservée en cas d'erreur

//  TRAITEMENT POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $email  = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
  $mdp    = $_POST['mot_de_passe'] ?? '';
  $email_v = $email;

  if (empty($email) || empty($mdp)) {
    $erreur = 'Veuillez remplir tous les champs.';
  } else {
    $pdo  = connexionDB();
    $stmt = $pdo->prepare("SELECT * FROM candidats WHERE email = ? AND statut = 'actif'");
    $stmt->execute([$email]);
    $candidat = $stmt->fetch();

    if ($candidat && password_verify($mdp, $candidat['mot_de_passe'])) {
      // --- Connexion réussie ---
      $_SESSION['candidat_id']  = $candidat['id'];
      $_SESSION['candidat_nom'] = $candidat['prenom'] . ' ' . $candidat['nom'];
      $_SESSION['candidat_email'] = $candidat['email'];

      // Mise à jour de la dernière connexion
      $pdo->prepare("UPDATE candidats SET derniere_connexion = NOW() WHERE id = ?")
          ->execute([$candidat['id']]);

      rediriger('espace.php');
    } else {
      $erreur = 'Email ou mot de passe incorrect.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion Candidat — CvMatchIA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="login.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

  
</head>
<body>

  <div class="login-carte">

    <a href="../index.php" class="logo-lien">CvMatch<span>IA</span></a>

    <div class="login-icone bleu">
    <i class="fa-solid fa-user-tie"></i>
</div>
    <h1 class="login-titre">Connexion Candidat</h1>
    <p class="login-sous-titre">Accédez à votre espace personnel</p>

    <?php if ($erreur): ?>
      <div class="alerte-erreur"><?= htmlspecialchars($erreur) ?></div>
    <?php endif; ?>

    <form method="POST" action="login.php" novalidate>

      <div class="champ">
        <label for="email">Adresse email</label>
        <input type="email" id="email" name="email"
          placeholder="amara.kone@email.com"
          value="<?= htmlspecialchars($email_v) ?>"
          required autofocus>
      </div>

      <div class="champ">
        <label for="mot_de_passe">Mot de passe</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe"
          placeholder="••••••••" required>
      </div>

      <div class="login-options">
        <label class="souvenir">
          <input type="checkbox" name="souvenir"> Se souvenir de moi
        </label>
        <a href="#" class="oublie">Mot de passe oublié ?</a>
      </div>

      <button type="submit" class="btn-principal">Se connecter →</button>

    </form>

    <div class="separateur">ou</div>

    <p class="lien-alternatif">
      Pas encore de compte ? <a href="register.php">S'inscrire gratuitement</a>
    </p>

    <a href="../index.php" class="retour">← Retour à l'accueil</a>

  </div>

</body>
</html>
