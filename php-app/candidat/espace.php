<?php

require_once '../config/config.php';
require_once '../config/auth_functions.php';
demarrerSession();

// Sécurité : si non connecté → page de connexion
if (empty($_SESSION['candidat_id'])) {
  rediriger('login.php');
}

$candidat_id  = $_SESSION['candidat_id'];
$candidat_nom = $_SESSION['candidat_nom'] ?? 'Candidat';

//  Récupérer les données existantes du candidat 
$pdo   = connexionDB();
$stmt  = $pdo->prepare("SELECT * FROM candidats WHERE id = ?");
$stmt->execute([$candidat_id]);
$candidat = $stmt->fetch();

// Récupérer le dernier CV uploadé 
$stmtCv = $pdo->prepare("SELECT * FROM cv WHERE candidat_id = ? ORDER BY date_upload DESC LIMIT 1");
$stmtCv->execute([$candidat_id]);
$cv_existant = $stmtCv->fetch();

// TRAITEMENT DES SOUMISSIONS POST

$messages = [];   // messages de retour (succès ou erreur)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $action = $_POST['action'] ?? '';

  //  STEP 1 : Informations personnelles 
  if ($action === 'sauver_personnel') {

    $prenom    = nettoyer($_POST['prenom']    ?? '');
    $nom       = nettoyer($_POST['nom']       ?? '');
    $telephone = nettoyer($_POST['telephone'] ?? '');
    $ville     = nettoyer($_POST['ville']     ?? '');
    $date_naissance = nettoyer($_POST['date_naissance'] ?? '');
    $genre     = nettoyer($_POST['genre']     ?? '');
    $linkedin  = nettoyer($_POST['linkedin']  ?? '');

    if (empty($prenom) || empty($nom)) {
      $messages['step1'] = ['type' => 'erreur', 'texte' => 'Prénom et nom sont obligatoires.'];
    } else {
      $pdo->prepare("
        UPDATE candidats
        SET prenom=?, nom=?, telephone=?, ville=?, date_naissance=?, genre=?, linkedin=?
        WHERE id=?
      ")->execute([$prenom, $nom, $telephone, $ville, $date_naissance ?: null, $genre, $linkedin, $candidat_id]);

      $_SESSION['candidat_nom'] = $prenom . ' ' . $nom;
      $messages['step1'] = ['type' => 'succes', 'texte' => 'Informations personnelles sauvegardées !'];

      // Recharger les données
      $stmt->execute([$candidat_id]);
      $candidat = $stmt->fetch();
    }
  }

  //  STEP 2 : Informations professionnelles 
  if ($action === 'sauver_professionnel') {

    $poste_actuel    = nettoyer($_POST['poste_actuel']    ?? '');
    $experience_ans  = intval($_POST['experience_ans']    ?? 0);
    $secteur         = nettoyer($_POST['secteur']         ?? '');
    $niveau_etude    = nettoyer($_POST['niveau_etude']    ?? '');
    $competences     = nettoyer($_POST['competences']     ?? '');
    $disponibilite   = nettoyer($_POST['disponibilite']   ?? '');
    $salaire_souhaite = nettoyer($_POST['salaire_souhaite'] ?? '');
    $bio             = nettoyer($_POST['bio']             ?? '');

    // Vérifier si les colonnes pro existent (ajout dynamique si besoin)
    // Ces colonnes sont ajoutées via un ALTER TABLE ci-dessous si absentes
    try {
      $pdo->prepare("
        UPDATE candidats
        SET poste_actuel=?, experience_ans=?, secteur=?,
            niveau_etude=?, competences=?, disponibilite=?,
            salaire_souhaite=?, bio=?
        WHERE id=?
      ")->execute([
        $poste_actuel, $experience_ans, $secteur,
        $niveau_etude, $competences, $disponibilite,
        $salaire_souhaite, $bio, $candidat_id
      ]);
      $messages['step2'] = ['type' => 'succes', 'texte' => 'Informations professionnelles sauvegardées !'];
      $stmt->execute([$candidat_id]);
      $candidat = $stmt->fetch();
    } catch (\PDOException $e) {
      $messages['step2'] = ['type' => 'erreur', 'texte' => 'Erreur SQL. Vérifiez les colonnes (voir commentaire ALTER TABLE).'];
    }
  }

  //  STEP 3 : Upload du CV + Photo professionnelle 
  if ($action === 'uploader_cv') {

    $erreurs_step3 = [];
    $succes_step3  = [];

    //  3a. UPLOAD DU CV (obligatoire)

    $types_cv      = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];
    $taille_max_ko = 5120;  // 5 Mo

    if (!isset($_FILES['cv_fichier']) || $_FILES['cv_fichier']['error'] !== UPLOAD_ERR_OK) {
      $erreurs_step3[] = 'CV : aucun fichier reçu ou erreur d\'upload.';
    } else {
      $fichier   = $_FILES['cv_fichier'];
      $nom_orig  = $fichier['name'];
      $extension = strtolower(pathinfo($nom_orig, PATHINFO_EXTENSION));
      $taille_ko = intval($fichier['size'] / 1024);

      if (!in_array($extension, $types_cv)) {
        $erreurs_step3[] = 'CV : format non autorisé (PDF, DOCX, DOC, JPG, PNG).';
      } elseif ($taille_ko > $taille_max_ko) {
        $erreurs_step3[] = 'CV : fichier trop lourd (max. 5 Mo).';
      } else {
        $dossier_cv = UPLOAD_CV_DIR;
        if (!is_dir($dossier_cv)) mkdir($dossier_cv, 0755, true);

        $nom_unique_cv = 'cv_' . $candidat_id . '_' . time() . '.' . $extension;

        if (move_uploaded_file($fichier['tmp_name'], $dossier_cv . $nom_unique_cv)) {
          $pdo->prepare("
            INSERT INTO cv (candidat_id, nom_fichier, chemin, type_fichier, taille_ko)
            VALUES (?, ?, ?, ?, ?)
          ")->execute([$candidat_id, $nom_orig, 'uploads/cv/' . $nom_unique_cv, $extension, $taille_ko]);

          $succes_step3[] = "CV « $nom_orig » uploadé. L'IA va l'analyser.";

          $stmtCv->execute([$candidat_id]);
          $cv_existant = $stmtCv->fetch();
        } else {
          $erreurs_step3[] = 'CV : impossible d\'enregistrer le fichier (permissions ?).';
        }
      }
    }

     
      //3b. UPLOAD DE LA PHOTO (facultatif)
    $photo_uploadee = isset($_FILES['photo_profil'])
                   && $_FILES['photo_profil']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($photo_uploadee) {
      $types_photo    = ['jpg', 'jpeg', 'png', 'webp'];
      $taille_max_photo = 2048;  // 2 Mo

      $fp        = $_FILES['photo_profil'];
      $ext_photo = strtolower(pathinfo($fp['name'], PATHINFO_EXTENSION));
      $taille_ph = intval($fp['size'] / 1024);

      if ($fp['error'] !== UPLOAD_ERR_OK) {
        $erreurs_step3[] = 'Photo : erreur lors de l\'upload.';
      } elseif (!in_array($ext_photo, $types_photo)) {
        $erreurs_step3[] = 'Photo : format non autorisé (JPG, PNG, WEBP uniquement).';
      } elseif ($taille_ph > $taille_max_photo) {
        $erreurs_step3[] = 'Photo : fichier trop lourd (max. 2 Mo).';
      } else {
        // Vérification que c'est bien une image (sécurité)
        $infos_image = @getimagesize($fp['tmp_name']);
        if (!$infos_image) {
          $erreurs_step3[] = 'Photo : le fichier n\'est pas une image valide.';
        } else {
          $dossier_photo = UPLOAD_PHOTO_DIR;
          if (!is_dir($dossier_photo)) mkdir($dossier_photo, 0755, true);

          // Supprimer l'ancienne photo si elle existe
          if (!empty($candidat['photo'])) {
            $ancienne = UPLOAD_PHOTO_DIR . basename($candidat['photo']);
            if (file_exists($ancienne)) @unlink($ancienne);
          }

          $nom_photo = 'photo_' . $candidat_id . '_' . time() . '.' . $ext_photo;

          if (move_uploaded_file($fp['tmp_name'], $dossier_photo . $nom_photo)) {
            // Sauvegarder le chemin relatif dans la colonne `photo` de la table candidats
            $pdo->prepare("UPDATE candidats SET photo = ? WHERE id = ?")
                ->execute(['uploads/photos/' . $nom_photo, $candidat_id]);

            $succes_step3[] = 'Photo professionnelle enregistrée avec succès.';

            // Recharger le candidat pour afficher la nouvelle photo
            $stmt->execute([$candidat_id]);
            $candidat = $stmt->fetch();
          } else {
            $erreurs_step3[] = 'Photo : impossible d\'enregistrer (permissions ?).';
          }
        }
      }
    }

    //  Construire le message de retour 
    if (!empty($erreurs_step3)) {
      $messages['step3'] = [
        'type'  => 'erreur',
        'texte' => implode('<br>', $erreurs_step3),
      ];
    } elseif (!empty($succes_step3)) {
      $messages['step3'] = [
        'type'  => 'succes',
        'texte' => implode(' · ', $succes_step3),
      ];
    }
  }

  //  FORMULAIRE CONTACT / AIDE 
  if ($action === 'envoyer_message') {
    $sujet   = nettoyer($_POST['sujet']   ?? '');
    $message = nettoyer($_POST['message'] ?? '');

    if (empty($sujet) || empty($message)) {
      $messages['contact'] = ['type' => 'erreur', 'texte' => 'Veuillez remplir tous les champs.'];
    } else {
      // Ici vous pouvez envoyer un email avec mail() ou PHPMailer
      $messages['contact'] = ['type' => 'succes', 'texte' => 'Message envoyé ! Nous vous répondrons sous 24h.'];
    }
  }
}

// Calcul du % de complétion du profil 
$champs_profil = ['prenom','nom','telephone','ville','poste_actuel','competences','bio'];
$remplis = 0;
foreach ($champs_profil as $c) {
  if (!empty($candidat[$c])) $remplis++;
}
$pct_profil = intval(($remplis / count($champs_profil)) * 100);
if ($cv_existant) $pct_profil = min(100, $pct_profil + 14);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mon Espace Candidat — CvMatchIA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="espace.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">

</head>
<body>

  <!--  NAVIGATION FIXE -->
  <nav>
    <a href="../index.php" class="nav-logo">CvMatch<span>IA</span></a>
    <div class="nav-droite">
      <span class="nav-salut">Bonjour, <strong><?= htmlspecialchars($candidat_nom) ?></strong></span>
      <a href="dashboard.php" class="btn-nav btn-dashboard">Mon Dashboard</a>
      <a href="logout.php"    class="btn-nav btn-deconnexion">Déconnexion</a>
    </div>
  </nav>


  <!-- PARTIE 1 — HERO IMAGE + TEXTE -->
  <section class="hero-section" id="hero">

    <div class="hero-image" id="hero-image"></div>
    <div class="hero-overlay"></div>

    <div class="hero-contenu">
      <span class="hero-badge">✦ Votre espace candidat</span>

      <h1 class="hero-titre">
        Construisez votre<br>
        profil <span class="accent">gagnant</span>
      </h1>

      <p class="hero-desc">
        Complétez vos informations personnelles et professionnelles,
        puis déposez votre CV. Notre IA l'analysera automatiquement
        pour vous mettre en valeur auprès des recruteurs.
      </p>

      <!-- Barre de progression du profil -->
      <div class="profil-progress">
        <div class="progress-label">
          <span>Complétion de votre profil</span>
          <strong><?= $pct_profil ?>%</strong>
        </div>
        <div class="progress-barre">
          <div class="progress-fill" style="width: <?= $pct_profil ?>%"></div>
        </div>
      </div>
    </div>

    <!-- Flèche scroll vers les steps -->
    <a href="#steps" class="scroll-arrow">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 5v14M5 12l7 7 7-7"/>
      </svg>
      Compléter mon profil
    </a>

  </section>


  <!-- PARTIE 2 — FORMULAIRE EN 3 STEPS -->
  <section class="steps-section" id="steps">

    <div class="section-entete">
      <h2>Complétez votre dossier</h2>
      <p>3 étapes simples pour créer un profil complet et visible par les recruteurs.</p>
    </div>

    <!-- Indicateur de progression (Step 1 / 2 / 3) -->
    <div class="steps-indicateur" id="steps-indicateur">
      <div class="step-numero actif" id="ind-1">
        <div class="cercle-step"><span class="chiffre-step">1</span></div>
        <span class="label-step">Informations<br>personnelles</span>
      </div>
      <div class="step-numero" id="ind-2">
        <div class="cercle-step"><span class="chiffre-step">2</span></div>
        <span class="label-step">Profil<br>professionnel</span>
      </div>
      <div class="step-numero" id="ind-3">
        <div class="cercle-step"><span class="chiffre-step">3</span></div>
        <span class="label-step">Envoi<br>du CV</span>
      </div>
    </div>

    <!-- Carte des formulaires -->
    <div class="step-carte">

      <!-- ═══ STEP 1 : Infos personnelles ═══ -->
      <div class="step-panneau actif" id="step-1">

        <h3 class="step-titre"><i class="fa-solid fa-user-tie"></i> Informations personnelles</h3>
        <p class="step-sous-titre">Ces informations seront visibles par les recruteurs qui consultent votre profil.</p>

        <?php if (!empty($messages['step1'])): ?>
          <div class="msg-alerte msg-<?= $messages['step1']['type'] ?>">
            <?= $messages['step1']['texte'] ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="espace.php#steps" id="form-step1">
          <input type="hidden" name="action" value="sauver_personnel">

          <div class="champs-grille">

            <div class="champ-group">
              <label for="prenom">Prénom *</label>
              <input type="text" id="prenom" name="prenom"
                placeholder="Amara"
                value="<?= htmlspecialchars($candidat['prenom'] ?? '') ?>" required>
            </div>

            <div class="champ-group">
              <label for="nom">Nom *</label>
              <input type="text" id="nom" name="nom"
                placeholder="Koné"
                value="<?= htmlspecialchars($candidat['nom'] ?? '') ?>" required>
            </div>

            <div class="champ-group">
              <label for="telephone">Téléphone</label>
              <input type="tel" id="telephone" name="telephone"
                placeholder="+225 07 00 00 00"
                value="<?= htmlspecialchars($candidat['telephone'] ?? '') ?>">
            </div>

            <div class="champ-group">
              <label for="ville">Ville de résidence</label>
              <input type="text" id="ville" name="ville"
                placeholder="Abidjan"
                value="<?= htmlspecialchars($candidat['ville'] ?? '') ?>">
            </div>

            <div class="champ-group">
              <label for="date_naissance">Date de naissance</label>
              <input type="date" id="date_naissance" name="date_naissance"
                value="<?= htmlspecialchars($candidat['date_naissance'] ?? '') ?>">
            </div>

            <div class="champ-group">
              <label for="genre">Genre</label>
              <select id="genre" name="genre">
                <option value="">-- Sélectionner --</option>
                <option value="homme"  <?= ($candidat['genre'] ?? '') === 'homme'  ? 'selected' : '' ?>>Homme</option>
                <option value="femme"  <?= ($candidat['genre'] ?? '') === 'femme'  ? 'selected' : '' ?>>Femme</option>
                <option value="autre"  <?= ($candidat['genre'] ?? '') === 'autre'  ? 'selected' : '' ?>>Autre / Non précisé</option>
              </select>
            </div>

            <div class="champ-group pleine">
              <label for="linkedin">Profil LinkedIn (lien)</label>
              <input type="url" id="linkedin" name="linkedin"
                placeholder="https://linkedin.com/in/amara-kone"
                value="<?= htmlspecialchars($candidat['linkedin'] ?? '') ?>">
            </div>

          </div>

          <div class="step-nav">
            <button type="submit" class="btn-step btn-sauver">
              <i class="fa-solid fa-floppy-disk"></i> Sauvegarder</button>
            <button type="button" class="btn-step btn-suivant" onclick="allerStep(2)">
              Suivant → Profil professionnel
            </button>
          </div>

        </form>
      </div>


      <!--  STEP 2 : Infos professionnelles --> 
      <div class="step-panneau" id="step-2">

        <h3 class="step-titre">
           <i class="fa-solid fa-briefcase"></i> Profil professionnel</h3>
        <p class="step-sous-titre">Ces données sont utilisées par l'IA pour calculer votre score de matching.</p>

        <?php if (!empty($messages['step2'])): ?>
          <div class="msg-alerte msg-<?= $messages['step2']['type'] ?>">
            <?= $messages['step2']['texte'] ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="espace.php#steps" id="form-step2">
          <input type="hidden" name="action" value="sauver_professionnel">

          <div class="champs-grille">

            <div class="champ-group pleine">
              <label for="poste_actuel">Poste actuel / recherché *</label>
              <input type="text" id="poste_actuel" name="poste_actuel"
                placeholder="Développeur Full-Stack PHP"
                value="<?= htmlspecialchars($candidat['poste_actuel'] ?? '') ?>">
            </div>

            <div class="champ-group">
              <label for="experience_ans">Années d'expérience</label>
              <select id="experience_ans" name="experience_ans">
                <option value="0"  <?= ($candidat['experience_ans'] ?? 0) == 0 ? 'selected':'' ?>>Débutant (0 an)</option>
                <option value="1"  <?= ($candidat['experience_ans'] ?? 0) == 1 ? 'selected':'' ?>>1 an</option>
                <option value="2"  <?= ($candidat['experience_ans'] ?? 0) == 2 ? 'selected':'' ?>>2 ans</option>
                <option value="3"  <?= ($candidat['experience_ans'] ?? 0) == 3 ? 'selected':'' ?>>3 ans</option>
                <option value="5"  <?= ($candidat['experience_ans'] ?? 0) == 5 ? 'selected':'' ?>>4-5 ans</option>
                <option value="7"  <?= ($candidat['experience_ans'] ?? 0) == 7 ? 'selected':'' ?>>6-8 ans</option>
                <option value="10" <?= ($candidat['experience_ans'] ?? 0) >= 10 ? 'selected':'' ?>>10 ans et plus</option>
              </select>
            </div>

            <div class="champ-group">
              <label for="secteur">Secteur d'activité</label>
              <select id="secteur" name="secteur">
                <option value="">-- Sélectionner --</option>
                <?php
                $secteurs = ['Informatique / Tech','Finance / Banque','Télécommunications',
                  'Commerce / Vente','Marketing / Communication','BTP / Génie civil',
                  'Santé / Médical','Éducation / Formation','Logistique / Transport','Autre'];
                foreach ($secteurs as $s):
                  $sel = ($candidat['secteur'] ?? '') === $s ? 'selected' : '';
                ?>
                  <option value="<?= $s ?>" <?= $sel ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="champ-group">
              <label for="niveau_etude">Niveau d'études</label>
              <select id="niveau_etude" name="niveau_etude">
                <option value="">-- Sélectionner --</option>
                <?php
                $niveaux = ['BEP / CAP','Baccalauréat','Licence (Bac+3)','Master (Bac+5)','Doctorat','Ingénieur','Autodidacte'];
                foreach ($niveaux as $n):
                  $sel = ($candidat['niveau_etude'] ?? '') === $n ? 'selected' : '';
                ?>
                  <option value="<?= $n ?>" <?= $sel ?>><?= $n ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="champ-group">
              <label for="disponibilite">Disponibilité</label>
              <select id="disponibilite" name="disponibilite">
                <option value="">-- Sélectionner --</option>
                <option value="immediat"   <?= ($candidat['disponibilite'] ?? '') === 'immediat'   ? 'selected':'' ?>>Immédiatement</option>
                <option value="1_mois"     <?= ($candidat['disponibilite'] ?? '') === '1_mois'     ? 'selected':'' ?>>Dans 1 mois</option>
                <option value="3_mois"     <?= ($candidat['disponibilite'] ?? '') === '3_mois'     ? 'selected':'' ?>>Dans 3 mois</option>
                <option value="a_discuter" <?= ($candidat['disponibilite'] ?? '') === 'a_discuter' ? 'selected':'' ?>>À discuter</option>
              </select>
            </div>

            <div class="champ-group">
              <label for="salaire_souhaite">Prétention salariale (FCFA)</label>
              <input type="text" id="salaire_souhaite" name="salaire_souhaite"
                placeholder="350 000 FCFA / mois"
                value="<?= htmlspecialchars($candidat['salaire_souhaite'] ?? '') ?>">
            </div>

            <!-- Compétences — utilisées par l'IA pour le matching -->
            <div class="champ-group pleine">
              <label for="competences">Compétences clés <small style="color:#94A3B8">(séparées par des virgules)</small></label>
              <input type="text" id="competences" name="competences"
                placeholder="PHP, MySQL, JavaScript, React, PowerBI…"
                value="<?= htmlspecialchars($candidat['competences'] ?? '') ?>">
            </div>

            <div class="champ-group pleine">
              <label for="bio">Résumé / Présentation personnelle</label>
              <textarea id="bio" name="bio"
                placeholder="Décrivez votre parcours, vos ambitions et ce qui vous distingue…"><?= htmlspecialchars($candidat['bio'] ?? '') ?></textarea>
            </div>

          </div>

          <div class="step-nav">
            <button type="button" class="btn-step btn-precedent" onclick="allerStep(1)">
              ← Retour
            </button>
            <button type="submit" class="btn-step btn-sauver">
              <i class="fa-solid fa-floppy-disk"></i> Sauvegarder</button>
            <button type="button" class="btn-step btn-suivant" onclick="allerStep(3)">
              Suivant → Envoyer mon CV
            </button>
          </div>

        </form>
      </div>


      <!--  STEP 3 : Upload CV + Photo professionnelle  -->
      <div class="step-panneau" id="step-3">

        <h3 class="step-titre">
          <i class="fa-solid fa-address-card"></i> CV & Photo professionnelle</h3>
        <p class="step-sous-titre">
          Déposez votre CV et ajoutez une photo professionnelle pour
          renforcer votre profil. L'IA analysera votre CV automatiquement.
        </p>

        <?php if (!empty($messages['step3'])): ?>
          <div class="msg-alerte msg-<?= $messages['step3']['type'] ?>">
            <?= $messages['step3']['texte'] ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="upload_cv.php" enctype="multipart/form-data" id="form-step3">
          <input type="hidden" name="action" value="uploader_cv">

          <!-- GRILLE 2 COLONNES : Photo | CV -->
          <div style="display:grid;grid-template-columns:200px 1fr;gap:24px;align-items:start;margin-bottom:20px;">

            <!-- ── COLONNE GAUCHE : Photo de profil ── -->
            <div>
              <p style="font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:10px;">
                 Photo de profil
                <span style="font-size:0.7rem;font-weight:400;color:#94A3B8;">(facultatif)</span>
              </p>

              <!-- Cercle de prévisualisation -->
              <div style="position:relative;width:fit-content;margin:0 auto 12px;">
                <div id="photo-cercle" style="
                  width:130px;height:130px;border-radius:50%;
                  border:3px dashed var(--bleu-vif);
                  background:var(--bleu-pale);
                  display:flex;flex-direction:column;
                  align-items:center;justify-content:center;
                  cursor:pointer;overflow:hidden;
                  transition:border-color 0.2s,background 0.2s;
                  margin:0 auto;
                " onclick="document.getElementById('input-photo').click()">

                  <?php if (!empty($candidat['photo'])): ?>
                    <!-- Photo existante en base -->
                    <img id="photo-preview-img"
                         src="../<?= htmlspecialchars($candidat['photo']) ?>"
                         alt="Photo de profil"
                         style="width:100%;height:100%;object-fit:cover;display:block;">
                    <div id="photo-placeholder" style="display:none;flex-direction:column;align-items:center;gap:4px;">
                      <span style="font-size: 1.8rem; color: #1A2A6C; display: inline-flex; align-items: center; justify-content: center;">
    <i class="fa-solid fa-user-circle"></i>
</span>
                      <span style="font-size:0.68rem;color:var(--bleu-vif);text-align:center;padding:0 8px;">Cliquer pour changer</span>
                    </div>
                  <?php else: ?>
                    <img id="photo-preview-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none;">
                    <div id="photo-placeholder" style="display:flex;flex-direction:column;align-items:center;gap:4px;">
                     <span style="font-size: 1.8rem; color: #1A2A6C; display: inline-flex; align-items: center; justify-content: center;">
    <i class="fa-solid fa-camera"></i>
</span>
                      <span style="font-size:0.68rem;color:var(--bleu-vif);text-align:center;padding:0 8px;">Cliquer pour ajouter</span>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Badge "Modifier" flottant -->
                <div onclick="document.getElementById('input-photo').click()" style="
                  position:absolute;bottom:4px;right:4px;
                  width:30px;height:30px;border-radius:50%;
                  background:var(--bleu-vif);color:var(--blanc);
                  display:flex;align-items:center;justify-content:center;
                  font-size:0.8rem;cursor:pointer;
                  box-shadow:0 2px 8px rgba(29,111,235,0.4);
                  border:2px solid var(--blanc);
                " title="Modifier la photo"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
              </div>

              <!-- Input photo caché -->
              <input type="file" id="input-photo" name="photo_profil"
                     accept=".jpg,.jpeg,.png,.webp"
                     style="display:none">

              <!-- Statut photo -->
              <div id="photo-statut" style="text-align:center;font-size:0.72rem;color:var(--gris-texte);line-height:1.4;">
                <?php if (!empty($candidat['photo'])): ?>
                  <span style="color:var(--vert);">✅ Photo enregistrée</span><br>
                  <span style="color:#94A3B8;">Cliquez pour en changer</span>
                <?php else: ?>
                  JPG, PNG, WEBP<br>max 2 Mo
                <?php endif; ?>
              </div>
            </div>

            <!-- COLONNE DROITE : Upload CV  -->
            <div>
              <p style="font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:10px;">
                Votre CV
                <span style="font-size:0.7rem;font-weight:400;color:#94A3B8;">(obligatoire)</span>
              </p>

              <!-- CV déjà en base -->
              <?php if ($cv_existant): ?>
                <div class="cv-existant" style="margin-bottom:12px;">
                  <div class="cv-existant-icone">📎</div>
                  <div class="cv-existant-info">
                    <strong><?= htmlspecialchars($cv_existant['nom_fichier']) ?></strong><br>
                    <small>
                      Déposé le <?= date('d/m/Y à H:i', strtotime($cv_existant['date_upload'])) ?>
                      — <?= $cv_existant['taille_ko'] ?> Ko
                    </small>
                  </div>
                  <span class="cv-existant-statut statut-<?= $cv_existant['statut_analyse'] ?>">
                    <?= $cv_existant['statut_analyse'] === 'analyse' ? '✅ Analysé' : ' En attente' ?>
                  </span>
                </div>
              <?php endif; ?>

              <!-- Zone de drop CV -->
              <div class="zone-upload" id="zone-drop"
                   onclick="document.getElementById('input-cv').click()"
                   ondragover="survol(event)" ondragleave="finSurvol()" ondrop="deposer(event)"
                   style="padding:28px 20px;">
              <span class="upload-icone"><i class="fa-solid fa-cloud-arrow-up"></i></span>
                <p class="upload-titre" style="font-size:0.95rem;">
                  <?= $cv_existant ? 'Déposer un nouveau CV' : 'Glissez-déposez votre CV ici' ?>
                </p>
                <p class="upload-desc">ou cliquez pour sélectionner</p>
                <div class="upload-formats">
                  <span class="format-badge">PDF</span>
                  <span class="format-badge">DOCX</span>
                  <span class="format-badge">DOC</span>
                  <span class="format-badge">JPG</span>
                  <span class="format-badge">PNG</span>
                </div>
                <p style="font-size:0.72rem;color:#94A3B8;margin-top:10px;">Max 5 Mo</p>
              </div>

              <input type="file" id="input-cv" name="cv_fichier"
                     accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">

              <!-- Aperçu du fichier CV sélectionné -->
              <div class="fichier-preview" id="fichier-preview">
                <div class="fichier-icone" id="fichier-icone">📄</div>
                <div>
                  <div class="fichier-nom"    id="fichier-nom">—</div>
                  <div class="fichier-taille" id="fichier-taille">—</div>
                </div>
              </div>
            </div>

          </div><!-- fin grille -->

          <!--  Récapitulatif avant envoi  -->
          <div id="recap-upload" style="
            display:none;
            background:var(--bleu-pale);
            border:1px solid var(--bleu-clair);
            border-radius:12px;
            padding:14px 18px;
            font-size:0.82rem;
            color:#374151;
            margin-bottom:4px;
          ">
            <strong style="color:var(--bleu-fonce);display:block;margin-bottom:8px;">📋 Prêt à envoyer :</strong>
            <div id="recap-cv-line"    style="display:none;margin-bottom:4px;">📄 CV : <span id="recap-cv-nom"  style="color:var(--bleu-vif);"></span></div>
            <div id="recap-photo-line" style="display:none;">📸 Photo : <span id="recap-photo-nom" style="color:var(--vert);"></span></div>
          </div>

          <div class="step-nav">
            <button type="button" class="btn-step btn-precedent" onclick="allerStep(2)">
              ← Retour
            </button>
            <button type="submit" class="btn-step btn-suivant" id="btn-upload" disabled><i class="fa-solid fa-cloud-arrow-up"></i> Envoyer</button>
          </div>

        </form>
      </div>

    </div><!-- .step-carte -->
  </section>


  <!--PARTIE 3 — CONTACT & AIDE-->
  <section class="contact-section" id="contact">

    <div class="contact-entete">
      <h2>Besoin d'aide ?</h2>
      <p>Notre équipe est disponible pour vous accompagner dans votre recherche d'emploi.</p>
    </div>

    <div class="contact-grille">

      <!-- Coordonnées -->
      <div class="contact-infos">

        <div class="contact-item">
          <div class="contact-item-icone"style="color:white;"><i class="fa-solid fa-phone-flip"></i></div>
          <div>
            <div class="contact-item-label">Téléphone</div>
            <div class="contact-item-valeur">+225 27 22 00 00 00</div>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-item-icone"style="color:white;"><i class="fa-solid fa-envelope"></i></div>
          <div>
            <div class="contact-item-label">Email support</div>
            <div class="contact-item-valeur">support@smartrecruit.ci</div>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-item-icone"style="color:white;"><i class="fa-solid fa-location-dot"></i></div>
          <div>
            <div class="contact-item-label">Adresse</div>
            <div class="contact-item-valeur">Plateau, Abidjan — Côte d'Ivoire</div>
          </div>
        </div>

        <div class="contact-item">
          <div class="contact-item-icone"style="color:white;">
    <i class="fa-solid fa-clock"></i>
</div>
          <div>
            <div class="contact-item-label">Horaires</div>
            <div class="contact-item-valeur">Lun – Ven : 8h00 – 18h00</div>
          </div>
        </div>

      </div>

      <!-- Formulaire de contact -->
      <div class="contact-form-carte">

        <h3 class="contact-form-titre">Envoyez-nous un message</h3>

        <?php if (!empty($messages['contact'])): ?>
          <div class="msg-alerte msg-<?= $messages['contact']['type'] ?>" style="color:<?= $messages['contact']['type']==='succes' ? '#68D391':'#FC8181' ?>;background:<?= $messages['contact']['type']==='succes' ? 'rgba(56,161,105,0.12)':'rgba(229,62,62,0.12)' ?>;border:1px solid <?= $messages['contact']['type']==='succes' ? 'rgba(56,161,105,0.3)':'rgba(229,62,62,0.3)' ?>">
            <?= $messages['contact']['texte'] ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="espace.php#contact">
          <input type="hidden" name="action" value="envoyer_message">

          <div class="champ-contact">
            <label for="contact-sujet">Sujet de votre demande</label>
            <select id="contact-sujet" name="sujet">
              <option value="">-- Choisir un sujet --</option>
              <option value="Problème upload CV">Problème upload CV</option>
              <option value="Modifier mes informations">Modifier mes informations</option>
              <option value="Problème de connexion">Problème de connexion</option>
              <option value="Question sur mon profil">Question sur mon profil</option>
              <option value="Autre demande">Autre demande</option>
            </select>
          </div>

          <div class="champ-contact">
            <label for="contact-message">Votre message</label>
            <textarea id="contact-message" name="message"
              placeholder="Décrivez votre problème ou votre question…"></textarea>
          </div>

          <button type="submit" class="btn-contact">
            <i class="fa-solid fa-envelope"style="color:white;"></i> Envoyer le message
          </button>

        </form>
      </div>

    </div>
  </section>


  <!-- FOOTER-->
  <footer>
    <p>&copy; <?= date('Y') ?> <strong>CvMatchIA</strong> — Tous droits réservés.</p>
  </footer>

  <SCript src="espace.js"></SCript>
</body>
</html>
<?php

?>