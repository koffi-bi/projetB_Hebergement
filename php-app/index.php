<?php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CvMatch IA — Recrutement Intelligent</title>

  <!-- Google Fonts : Syne (titres) + DM Sans (corps) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  
</head>

<body>
  
  <!-- NAVIGATION FIXE -->
  <nav>
    
</div>
    <a href="index.php" class="logo">CvMatch<span>IA</span></a>
    <div class="nav-liens">
      <a href="candidat/login.php"  class="btn btn-outline">Connexion candidat</a>
      <a href="admin/login.php"     class="btn btn-bleu">Espace recruteur</a>
    </div>
  </nav>
  


  <!--SECTION HERO (partie haute — 100vh)-->
  <section class="hero" id="hero">

    <!-- GAUCHE : présentation du site -->
    <div class="hero-texte">

      <span class="badge">Propulsé par l'IA</span>

      <h1 class="hero-titre">
        Le recrutement<br>
        <span class="accent">intelligent</span>,<br>
        enfin accessible.
      </h1>

      <p class="hero-description">
        CvMatch IA analyse automatiquement les CV, extrait les compétences
        et classe les candidats selon vos critères en langage naturel —
        le tout en quelques secondes.
      </p>

      <div class="hero-cta">
        <!-- la section de choix du rôle -->
        <a href="#choisir-role" class="btn btn-bleu">Commencer maintenant</a>
        <a href="#choisir-role" class="btn btn-outline">En savoir plus</a>
      </div>

      <!-- Indicateur de scroll -->
      <div class="scroll-hint">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 5v14M5 12l7 7 7-7"/>
        </svg>
        Défiler pour accéder à votre espace
      </div>

    </div>

    <!-- DROITE : CV  animé -->
    <div class="hero-visuel">
      <div class="cv-mockup">

        <!-- Badge IA flottant -->
        <div class="badge-ia">
          ✦ Analyse IA : 92% match
        </div>

        <!-- En-tête du CV -->
        <div class="cv-header">
          <div class="cv-avatar">YN</div>
          <div>
            <div class="cv-nom">Yoh Bi Nene</div>
            <div class="cv-poste">Développeur Data IA · Abidjan</div>
          </div>
        </div>

        <!-- Lignes de texte simulées -->
        <div class="cv-section-titre">Résumé</div>
        <div class="cv-ligne w-90"></div>
        <div class="cv-ligne w-70"></div>
        <div class="cv-ligne w-80"></div>

        <div class="cv-section-titre">Expérience</div>
        <div class="cv-ligne w-80"></div>
        <div class="cv-ligne w-50"></div>

        <!-- Compétences extraites  -->
        <div class="cv-section-titre">Compétences détectées par l'IA</div>
        <div class="cv-tags">
          <span class="cv-tag">PHP</span>
          <span class="cv-tag">MySQL</span>
          <span class="cv-tag">Python</span>
          <span class="cv-tag">machine learning</span>
          <span class="cv-tag">PowerBI</span>
        </div>

        <!-- Score de matching flottant en bas à gauche -->
        <div class="score-badge">
          <div class="score-cercle">92%</div>
          <div class="score-texte">
            <div class="score-pct">Score : 92%</div>
            <div class="score-label">Correspondance requête</div>
          </div>
        </div>

      </div>
    </div>

  </section>

  <!-- SECTION CHOIX DU RÔLE (partie basse ) -->
  <section class="section-roles" id="choisir-role">

    <div class="section-titre">
      <h2>Choisissez votre espace</h2>
      <p>Accédez à votre interface personnalisée selon votre profil.</p>
    </div>

    <div class="grille-roles">

      <!-- CARTE CANDIDAT  -->
      <a href="candidat/register.php" class="carte-role">

         <div class="role-icone bleu">
    <i class="fa-solid fa-user-tie"></i>
</div>

        <div class="role-titre">Je suis Candidat</div>

        <p class="role-desc">
          Créez votre profil, déposez votre CV et soyez trouvé
          par les recruteurs grâce à l'analyse intelligente de votre dossier.
        </p>

        

        <span class="btn btn-role btn-role-bleu">S'inscrire / Se connecter →</span>

      </a>

      <!--  CARTE ADMINISTRATEUR / RECRUTEUR  -->
      <a href="admin/login.php" class="carte-role">

        <div class="role-icone fonce">
    <i class="fa-solid fa-magnifying-glass-chart"></i>
</div>

        <div class="role-titre">Je suis Recruteur</div>

        <p class="role-desc">
          Accédez au tableau de bord sécurisé, lancez des recherches
          en langage naturel et laissez l'IA identifier les meilleurs profils.
        </p>

        

        <span class="btn btn-role btn-role-fonce">Accéder au dashboard →</span>

      </a>

    </div>

  </section>

  <!-- FOOTER-->
  <footer>
    <p>&copy; <?php echo date('Y'); ?> <strong>CvMatch IA</strong> — Tous droits réservés.
    Recrutement intelligent propulsé par l'IA|Nene Yoh-Simplon.</p>
  </footer>

</body>
</html>
