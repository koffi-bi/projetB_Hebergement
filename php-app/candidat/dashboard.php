<?php
require_once '../config/config.php';
require_once '../config/auth_functions.php';
demarrerSession();

if (empty($_SESSION['candidat_id'])) {
  rediriger('login.php');
}

$candidat_id = $_SESSION['candidat_id'];
$pdo = connexionDB();

$stmt = $pdo->prepare("SELECT * FROM candidats WHERE id = ?");
$stmt->execute([$candidat_id]);
$candidat = $stmt->fetch();

$stmtCvs = $pdo->prepare("SELECT * FROM cv WHERE candidat_id = ? ORDER BY date_upload DESC");
$stmtCvs->execute([$candidat_id]);
$cvs = $stmtCvs->fetchAll();

$cv_actuel = $cvs[0] ?? null;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'modifier_personnel') {
    $prenom         = nettoyer($_POST['prenom']         ?? '');
    $nom            = nettoyer($_POST['nom']            ?? '');
    $telephone      = nettoyer($_POST['telephone']      ?? '');
    $ville          = nettoyer($_POST['ville']          ?? '');
    $date_naissance = nettoyer($_POST['date_naissance'] ?? '');
    $genre          = nettoyer($_POST['genre']          ?? '');
    $linkedin       = nettoyer($_POST['linkedin']       ?? '');
    if (empty($prenom) || empty($nom)) {
      $flash = ['type'=>'erreur','texte'=>'Prénom et nom sont obligatoires.'];
    } else {
      $pdo->prepare("UPDATE candidats SET prenom=?,nom=?,telephone=?,ville=?,date_naissance=?,genre=?,linkedin=? WHERE id=?")
          ->execute([$prenom,$nom,$telephone,$ville,$date_naissance?:null,$genre,$linkedin,$candidat_id]);
      $_SESSION['candidat_nom'] = $prenom.' '.$nom;
      $flash = ['type'=>'succes','texte'=>'Informations personnelles mises à jour.'];
    }
    $_SESSION['flash'] = $flash;
    rediriger('dashboard.php?onglet=personnel');
  }

  if ($action === 'modifier_professionnel') {
    $pdo->prepare("UPDATE candidats SET poste_actuel=?,experience_ans=?,secteur=?,niveau_etude=?,competences=?,disponibilite=?,salaire_souhaite=?,bio=? WHERE id=?")
        ->execute([nettoyer($_POST['poste_actuel']??''),intval($_POST['experience_ans']??0),nettoyer($_POST['secteur']??''),nettoyer($_POST['niveau_etude']??''),nettoyer($_POST['competences']??''),nettoyer($_POST['disponibilite']??''),nettoyer($_POST['salaire_souhaite']??''),nettoyer($_POST['bio']??''),$candidat_id]);
    $_SESSION['flash'] = ['type'=>'succes','texte'=>'Profil professionnel mis à jour.'];
    rediriger('dashboard.php?onglet=professionnel');
  }

  if ($action === 'changer_mdp') {
    $actuel  = $_POST['mdp_actuel']  ?? '';
    $nouveau = $_POST['mdp_nouveau'] ?? '';
    $confirm = $_POST['mdp_confirm'] ?? '';
    if (!password_verify($actuel, $candidat['mot_de_passe'])) {
      $flash = ['type'=>'erreur','texte'=>'Mot de passe actuel incorrect.'];
    } elseif (strlen($nouveau) < 8) {
      $flash = ['type'=>'erreur','texte'=>'Le nouveau mot de passe doit avoir au moins 8 caractères.'];
    } elseif ($nouveau !== $confirm) {
      $flash = ['type'=>'erreur','texte'=>'Les nouveaux mots de passe ne correspondent pas.'];
    } else {
      $pdo->prepare("UPDATE candidats SET mot_de_passe=? WHERE id=?")->execute([password_hash($nouveau, PASSWORD_BCRYPT),$candidat_id]);
      $flash = ['type'=>'succes','texte'=>'Mot de passe changé avec succès.'];
    }
    $_SESSION['flash'] = $flash;
    rediriger('dashboard.php?onglet=securite');
  }

  if ($action === 'maj_cv') {
    $types_ok = ['pdf','docx','doc','jpg','jpeg','png'];
    if (!isset($_FILES['cv_fichier']) || $_FILES['cv_fichier']['error'] !== UPLOAD_ERR_OK) {
      $flash = ['type'=>'erreur','texte'=>'Aucun fichier reçu.'];
    } else {
      $f      = $_FILES['cv_fichier'];
      $ext    = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $taille = intval($f['size'] / 1024);
      if (!in_array($ext, $types_ok) || $taille > 5120) {
        $flash = ['type'=>'erreur','texte'=>'Format invalide ou fichier trop lourd (max 5 Mo).'];
      } else {
        $dossier = UPLOAD_CV_DIR;
        if (!is_dir($dossier)) mkdir($dossier, 0755, true);
        $nomUniq = 'cv_'.$candidat_id.'_'.time().'.'.$ext;
        if (move_uploaded_file($f['tmp_name'], $dossier.$nomUniq)) {
          $pdo->prepare("INSERT INTO cv (candidat_id, nom_fichier, chemin, type_fichier, taille_ko) VALUES (?,?,?,?,?)")
              ->execute([$candidat_id, $f['name'], 'uploads/cv/'.$nomUniq, $ext, $taille]);
          $flash = ['type'=>'succes','texte'=>"CV mis à jour ! L'IA va l'analyser."];
        } else {
          $flash = ['type'=>'erreur','texte'=>'Erreur lors de l\'enregistrement du fichier.'];
        }
      }
    }
    $_SESSION['flash'] = $flash;
    rediriger('dashboard.php?onglet=cv');
  }

  if ($action === 'maj_photo') {
    $types_photo = ['jpg','jpeg','png','webp'];
    if (!isset($_FILES['photo_profil']) || $_FILES['photo_profil']['error'] !== UPLOAD_ERR_OK) {
      $flash = ['type'=>'erreur','texte'=>'Aucune photo reçue.'];
    } else {
      $fp     = $_FILES['photo_profil'];
      $ext    = strtolower(pathinfo($fp['name'], PATHINFO_EXTENSION));
      $taille = intval($fp['size'] / 1024);
      if (!in_array($ext, $types_photo)) {
        $flash = ['type'=>'erreur','texte'=>'Format non autorisé (JPG, PNG, WEBP).'];
      } elseif ($taille > 2048) {
        $flash = ['type'=>'erreur','texte'=>'Photo trop lourde (max 2 Mo).'];
      } elseif (!@getimagesize($fp['tmp_name'])) {
        $flash = ['type'=>'erreur','texte'=>"Le fichier n'est pas une image valide."];
      } else {
        $dossier = UPLOAD_PHOTO_DIR;
        if (!is_dir($dossier)) mkdir($dossier, 0755, true);
        if (!empty($candidat['photo'])) { $a = UPLOAD_PHOTO_DIR.basename($candidat['photo']); if (file_exists($a)) @unlink($a); }
        $nomPhoto = 'photo_'.$candidat_id.'_'.time().'.'.$ext;
        if (move_uploaded_file($fp['tmp_name'], $dossier.$nomPhoto)) {
          $pdo->prepare("UPDATE candidats SET photo=? WHERE id=?")->execute(['uploads/photos/'.$nomPhoto,$candidat_id]);
          $flash = ['type'=>'succes','texte'=>'Photo de profil mise à jour avec succès.'];
        } else {
          $flash = ['type'=>'erreur','texte'=>'Erreur lors de l\'enregistrement de la photo.'];
        }
      }
    }
    $_SESSION['flash'] = $flash;
    rediriger('dashboard.php?onglet=cv');
  }

  $stmt->execute([$candidat_id]);
  $candidat = $stmt->fetch();
  $stmtCvs->execute([$candidat_id]);
  $cvs = $stmtCvs->fetchAll();
  $cv_actuel = $cvs[0] ?? null;
}

$onglet = $_GET['onglet'] ?? 'apercu';
if (!in_array($onglet, ['apercu','personnel','professionnel','cv','securite'])) $onglet = 'apercu';

$champs_profil = ['prenom','nom','telephone','ville','poste_actuel','competences','bio'];
$remplis = 0;
foreach ($champs_profil as $c) { if (!empty($candidat[$c])) $remplis++; }
$pct = intval(($remplis / count($champs_profil)) * 100);
if ($cv_actuel) $pct = min(100, $pct + 14);

$tags_competences = [];
if (!empty($candidat['competences'])) {
  $tags_competences = array_map('trim', explode(',', $candidat['competences']));
}

// Titres des onglets avec icônes SVG
$titres_onglets = [
  'apercu'        => 'Vue d\'ensemble',
  'personnel'     => 'Informations personnelles',
  'professionnel' => 'Profil professionnel',
  'cv'            => 'Mon CV & Photo',
  'securite'      => 'Sécurité',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mon Dashboard — CvMatchIA</title>

  <!-- Polices : Syne (titres) + DM Sans (corps) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="dashboard.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">

    
</head>
<body>

<div class="dashboard-layout">

  <!-- =
       SIDEBAR GAUCHE
   -->
  <aside class="sidebar">

    <div class="sidebar-header">
      <a href="../index.php" class="sidebar-logo">CvMatch<span>IA</span></a>

      <div class="sidebar-user">
        <?php if (!empty($candidat['photo'])): ?>
          <img src="../<?= htmlspecialchars($candidat['photo']) ?>"
               alt="Photo" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid rgba(255,255,255,0.15);">
        <?php else: ?>
          <div class="user-avatar">
            <?= strtoupper(mb_substr($candidat['prenom']??'C',0,1).mb_substr($candidat['nom']??'',0,1)) ?>
          </div>
        <?php endif; ?>
        <div>
          <div class="user-nom"><?= htmlspecialchars(($candidat['prenom']??'').' '.($candidat['nom']??'')) ?></div>
          <div class="user-role">Candidat · <?= htmlspecialchars($candidat['ville']??'—') ?></div>
        </div>
      </div>
    </div>

    <div class="sidebar-progress">
      <div class="prog-label">
        <span>Profil complété</span>
        <strong><?= $pct ?>%</strong>
      </div>
      <div class="prog-barre"><div class="prog-fill" style="width:<?= $pct ?>%"></div></div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Mon compte</div>

      <!-- Vue d'ensemble -->
      <a href="dashboard.php?onglet=apercu" class="nav-item <?= $onglet==='apercu'?'actif':'' ?>">
        <span class="nav-ico">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
        </span>
        Vue d'ensemble
      </a>

      <!-- Infos personnelles -->
      <a href="dashboard.php?onglet=personnel" class="nav-item <?= $onglet==='personnel'?'actif':'' ?>">
        <span class="nav-ico">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </span>
        Infos personnelles
      </a>

      <!-- Profil professionnel -->
      <a href="dashboard.php?onglet=professionnel" class="nav-item <?= $onglet==='professionnel'?'actif':'' ?>">
        <span class="nav-ico">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
        </span>
        Profil professionnel
      </a>

      <!-- Mon CV -->
      <a href="dashboard.php?onglet=cv" class="nav-item <?= $onglet==='cv'?'actif':'' ?>">
        <span class="nav-ico">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </span>
        Mon CV
        <?php if ($cv_actuel && $cv_actuel['statut_analyse']==='en_attente'): ?>
          <span style="margin-left:auto;width:7px;height:7px;background:var(--orange);border-radius:50%;flex-shrink:0;"></span>
        <?php endif; ?>
      </a>

      <!-- Sécurité -->
      <a href="dashboard.php?onglet=securite" class="nav-item <?= $onglet==='securite'?'actif':'' ?>">
        <span class="nav-ico">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </span>
        Sécurité
      </a>

      <div class="nav-section-label" style="margin-top:14px;">Accès rapide</div>

      <a href="espace.php" class="nav-item">
        <span class="nav-ico">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </span>
        Compléter mon profil
      </a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" class="btn-deconnexion-side">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Se déconnecter
      </a>
    </div>

  </aside>


  <!-- 
       CONTENU PRINCIPAL
   -->
  <main class="main-content">

    <!-- Top bar -->
    <div class="topbar">
      <!-- Bouton hamburger — visible uniquement sur mobile (< 900px) -->
      <button class="btn-hamburger" id="btn-hamburger"
              onclick="toggleSidebar()" aria-label="Ouvrir le menu">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
          <line x1="3" y1="6"  x2="21" y2="6"/>
          <line x1="3" y1="12" x2="21" y2="12"/>
          <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
      </button>
      <div>
        <div class="topbar-titre">
          <?php
          // Icônes SVG pour chaque onglet
          $icones = [
            'apercu'        => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
            'personnel'     => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'professionnel' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
            'cv'            => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'securite'      => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
          ];
          echo ($icones[$onglet] ?? '') . ' ' . htmlspecialchars($titres_onglets[$onglet] ?? '');
          ?>
        </div>
        <div class="topbar-breadcrumb">Dashboard Candidat</div>
      </div>
      <div class="topbar-actions">
        <a href="espace.php" class="btn-completer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
          Modifier mon profil
        </a>
      </div>
    </div>

    <!-- Corps -->
    <div class="page-body">

      <!-- Flash message -->
      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>">
          <?php if ($flash['type']==='succes'): ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <?php else: ?>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?php endif; ?>
          <?= htmlspecialchars($flash['texte']) ?>
        </div>
      <?php endif; ?>


      <?php if ($onglet === 'apercu'): ?>
      <!-- =
           ONGLET : VUE D'ENSEMBLE
      ===-->

        <!-- 4 cartes de statistiques -->
        <div class="stats-grille">
          <div class="stat-carte">
            <div class="stat-icone bleu">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            </div>
            <div><div class="stat-valeur"><?= $pct ?>%</div><div class="stat-label">Profil complété</div></div>
          </div>
          <div class="stat-carte">
            <div class="stat-icone vert">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div><div class="stat-valeur"><?= count($cvs) ?></div><div class="stat-label">CV déposé<?= count($cvs)>1?'s':'' ?></div></div>
          </div>
          <div class="stat-carte">
            <div class="stat-icone orange">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div><div class="stat-valeur"><?= $cv_actuel && $cv_actuel['statut_analyse']==='analyse' ? 'Oui' : 'Non' ?></div><div class="stat-label">CV analysé par l'IA</div></div>
          </div>
          <div class="stat-carte">
            <div class="stat-icone gris">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div><div class="stat-valeur"><?= $candidat['derniere_connexion'] ? date('d/m', strtotime($candidat['derniere_connexion'])) : '—' ?></div><div class="stat-label">Dernière connexion</div></div>
          </div>
        </div>

        <!-- Aperçu du profil -->
        <div class="profil-apercu-carte">

          <?php if (!empty($candidat['photo'])): ?>
            <img src="../<?= htmlspecialchars($candidat['photo']) ?>" alt="Photo de profil"
                 style="width:76px;height:76px;border-radius:50%;object-fit:cover;flex-shrink:0;border:3px solid var(--bleu-clair);">
          <?php else: ?>
            <div class="profil-avatar-lg">
              <?= strtoupper(mb_substr($candidat['prenom']??'C',0,1).mb_substr($candidat['nom']??'',0,1)) ?>
            </div>
          <?php endif; ?>

          <div style="flex:1;min-width:0;">
            <div class="profil-nom"><?= htmlspecialchars(($candidat['prenom']??'').' '.($candidat['nom']??'')) ?></div>
            <div class="profil-poste"><?= htmlspecialchars($candidat['poste_actuel']??'Poste non renseigné') ?></div>

            <div class="profil-meta">
              <?php if (!empty($candidat['ville'])): ?>
                <span class="meta-item">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  <?= htmlspecialchars($candidat['ville']) ?>
                </span>
              <?php endif; ?>
              <?php if (!empty($candidat['telephone'])): ?>
                <span class="meta-item">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  <?= htmlspecialchars($candidat['telephone']) ?>
                </span>
              <?php endif; ?>
              <?php if (!empty($candidat['experience_ans'])): ?>
                <span class="meta-item">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
                  <?= $candidat['experience_ans'] ?> ans d'expérience
                </span>
              <?php endif; ?>
              <?php if (!empty($candidat['disponibilite'])): ?>
                <span class="meta-item" style="color:var(--vert);">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                  <?= htmlspecialchars($candidat['disponibilite']) ?>
                </span>
              <?php endif; ?>
            </div>

            <?php if (!empty($tags_competences)): ?>
              <div class="tags-competences">
                <?php foreach (array_slice($tags_competences,0,8) as $tag): ?>
                  <span class="tag-comp"><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
                <?php if (count($tags_competences)>8): ?>
                  <span class="tag-comp" style="background:var(--gris-fond);color:#64748B;">+<?= count($tags_competences)-8 ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="profil-score-bloc">
            <div class="score-cercle-lg"><span class="score-pct-lg"><?= $pct ?>%</span></div>
            <div class="score-sous">Complétion<br>du profil</div>
          </div>

        </div>

        <!-- Conseils si profil incomplet -->
        <?php if ($pct < 100): ?>
        <div class="carte-conseils">
          <div class="carte-conseils-titre">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--bleu-vif)" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Améliorez votre visibilité auprès des recruteurs
          </div>
          <div class="carte-conseils-desc">Ces informations manquantes réduisent vos chances d'apparaître dans les résultats de recherche :</div>
          <ul class="conseils-liste">
            <?php
            $manques = [
              'telephone'    => ['Ajoutez votre numéro de téléphone',   'M(22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z'],
              'ville'        => ['Précisez votre ville de résidence',    'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z'],
              'poste_actuel' => ['Indiquez votre poste recherché',       'M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z'],
              'competences'  => ['Renseignez vos compétences clés',      'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z'],
              'bio'          => ['Rédigez votre présentation personnelle','M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7'],
            ];
            foreach ($manques as $champ => [$texte, $path]):
              if (empty($candidat[$champ])):
            ?>
              <li>
                <div class="conseil-puce">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="<?= $path ?>"/></svg>
                </div>
                <?= $texte ?>
              </li>
            <?php endif; endforeach; ?>
            <?php if (!$cv_actuel): ?>
              <li>
                <div class="conseil-puce">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                Uploadez votre CV
              </li>
            <?php endif; ?>
          </ul>
          <div style="margin-top:16px;">
            <a href="espace.php" class="btn-completer">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              Compléter maintenant
            </a>
          </div>
        </div>
        <?php endif; ?>


      <?php elseif ($onglet === 'personnel'): ?>
      <!-- =
           ONGLET : INFORMATIONS PERSONNELLES
       -->

        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Informations personnelles</div>
              <div class="form-carte-desc">Vos coordonnées visibles par les recruteurs</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="modifier_personnel">
            <div class="form-grille">

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  Prénom *
                </label>
                <input type="text" name="prenom" value="<?= htmlspecialchars($candidat['prenom']??'') ?>" required>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                  Nom *
                </label>
                <input type="text" name="nom" value="<?= htmlspecialchars($candidat['nom']??'') ?>" required>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                  Email
                  <span style="font-size:0.68rem;color:var(--gris-texte);font-weight:400;">(non modifiable)</span>
                </label>
                <input type="email" value="<?= htmlspecialchars($candidat['email']??'') ?>" disabled>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 2.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  Téléphone
                </label>
                <input type="tel" name="telephone" placeholder="+225 07 00 00 00" value="<?= htmlspecialchars($candidat['telephone']??'') ?>">
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  Ville de résidence
                </label>
                <input type="text" name="ville" placeholder="Abidjan" value="<?= htmlspecialchars($candidat['ville']??'') ?>">
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                  Date de naissance
                </label>
                <input type="date" name="date_naissance" value="<?= htmlspecialchars($candidat['date_naissance']??'') ?>">
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  Genre
                </label>
                <select name="genre">
                  <option value="">— Sélectionner —</option>
                  <option value="homme" <?= ($candidat['genre']??'')==='homme'?'selected':'' ?>>Homme</option>
                  <option value="femme" <?= ($candidat['genre']??'')==='femme'?'selected':'' ?>>Femme</option>
                  <option value="autre" <?= ($candidat['genre']??'')==='autre'?'selected':'' ?>>Autre / Non précisé</option>
                </select>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                  Profil LinkedIn
                </label>
                <input type="url" name="linkedin" placeholder="https://linkedin.com/in/…" value="<?= htmlspecialchars($candidat['linkedin']??'') ?>">
              </div>

            </div>
            <div class="form-footer">
              <button type="submit" class="btn-save">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Sauvegarder les modifications
              </button>
            </div>
          </form>
        </div>


      <?php elseif ($onglet === 'professionnel'): ?>
      <!-- ===
           ONGLET : PROFIL PROFESSIONNEL
    -->

        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Profil professionnel</div>
              <div class="form-carte-desc">Ces données alimentent l'IA pour calculer votre score de matching</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="modifier_professionnel">
            <div class="form-grille">

              <div class="champ-g pleine">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                  Poste actuel / recherché
                </label>
                <input type="text" name="poste_actuel" placeholder="Développeur Full-Stack PHP" value="<?= htmlspecialchars($candidat['poste_actuel']??'') ?>">
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  Années d'expérience
                </label>
                <select name="experience_ans">
                  <?php foreach ([0=>'Débutant (0 an)',1=>'1 an',2=>'2 ans',3=>'3 ans',5=>'4–5 ans',7=>'6–8 ans',10=>'10 ans et plus'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($candidat['experience_ans']??0)==$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                  Secteur d'activité
                </label>
                <select name="secteur">
                  <option value="">— Sélectionner —</option>
                  <?php foreach (['Informatique / Tech','Finance / Banque','Télécommunications','Commerce / Vente','Marketing / Communication','BTP / Génie civil','Santé / Médical','Éducation / Formation','Logistique / Transport','Autre'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($candidat['secteur']??'')===$s?'selected':'' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                  Niveau d'études
                </label>
                <select name="niveau_etude">
                  <option value="">— Sélectionner —</option>
                  <?php foreach (['BEP / CAP','Baccalauréat','Licence (Bac+3)','Master (Bac+5)','Doctorat','Ingénieur','Autodidacte'] as $n): ?>
                    <option value="<?= $n ?>" <?= ($candidat['niveau_etude']??'')===$n?'selected':'' ?>><?= $n ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                  Disponibilité
                </label>
                <select name="disponibilite">
                  <option value="">— Sélectionner —</option>
                  <option value="immediat"   <?= ($candidat['disponibilite']??'')==='immediat'  ?'selected':'' ?>>Immédiatement</option>
                  <option value="1_mois"     <?= ($candidat['disponibilite']??'')==='1_mois'    ?'selected':'' ?>>Dans 1 mois</option>
                  <option value="3_mois"     <?= ($candidat['disponibilite']??'')==='3_mois'    ?'selected':'' ?>>Dans 3 mois</option>
                  <option value="a_discuter" <?= ($candidat['disponibilite']??'')==='a_discuter'?'selected':'' ?>>À discuter</option>
                </select>
              </div>

              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                  Prétention salariale
                </label>
                <input type="text" name="salaire_souhaite" placeholder="350 000 FCFA / mois" value="<?= htmlspecialchars($candidat['salaire_souhaite']??'') ?>">
              </div>

              <div class="champ-g pleine">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                  Compétences clés
                  <span style="font-size:0.68rem;color:var(--gris-texte);font-weight:400;">séparées par des virgules</span>
                </label>
                <input type="text" name="competences" placeholder="PHP, MySQL, JavaScript, React, PowerBI…" value="<?= htmlspecialchars($candidat['competences']??'') ?>">
              </div>

              <div class="champ-g pleine">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  Présentation / Bio
                </label>
                <textarea name="bio" placeholder="Décrivez votre parcours, vos ambitions et ce qui vous distingue…"><?= htmlspecialchars($candidat['bio']??'') ?></textarea>
              </div>

            </div>
            <div class="form-footer">
              <button type="submit" class="btn-save">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Sauvegarder les modifications
              </button>
            </div>
          </form>
        </div>


      <?php elseif ($onglet === 'cv'): ?>
      <!-- =
           ONGLET : MON CV & PHOTO
      == -->

        <!-- Historique des CV -->
        <?php if (!empty($cvs)): ?>
        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Historique de vos CV</div>
              <div class="form-carte-desc">Le premier CV est le plus récent et utilisé par l'IA pour le matching</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <div class="cv-liste">
            <?php foreach ($cvs as $i => $cv):
              $icone_cv = match($cv['type_fichier']) {
                'pdf'  => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#FEE2E2" stroke="#EF4444"/><polyline points="14 2 14 8 20 8" stroke="#EF4444"/>',
                'docx','doc' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" fill="#DBEAFE" stroke="#3B82F6"/><polyline points="14 2 14 8 20 8" stroke="#3B82F6"/>',
                default => '<rect x="3" y="3" width="18" height="18" rx="2" fill="#DCFCE7" stroke="#22C55E"/><polyline points="3,9 21,9" stroke="#22C55E"/>'
              };
            ?>
              <div class="cv-item">
                <div class="cv-item-icone">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke-width="1.5" stroke-linecap="round"><?= $icone_cv ?></svg>
                </div>
                <div style="flex:1;min-width:0;">
                  <div class="cv-item-nom">
                    <?= htmlspecialchars($cv['nom_fichier']) ?>
                    <?php if ($i===0): ?><span class="cv-actuel-badge">Actuel</span><?php endif; ?>
                  </div>
                  <div class="cv-item-meta">
                    Déposé le <?= date('d/m/Y à H:i', strtotime($cv['date_upload'])) ?>
                    · <?= $cv['taille_ko'] ?> Ko · <?= strtoupper($cv['type_fichier']) ?>
                  </div>
                </div>
                <span class="cv-statut statut-<?= $cv['statut_analyse'] ?>">
                  <?= match($cv['statut_analyse']) { 'analyse'=>'Analysé', 'en_attente'=>'En attente', default=>'Erreur' } ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Upload nouveau CV -->
        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Mettre à jour mon CV</div>
              <div class="form-carte-desc">L'ancienne version sera conservée dans l'historique ci-dessus</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <form method="POST" action="dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="maj_cv">

            <div class="zone-upload-mini" onclick="document.getElementById('cv-input-dash').click()" ondragover="event.preventDefault()" ondrop="dropCV(event)">
              <div class="zone-upload-mini-ico">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              </div>
              <p><strong>Glissez ou cliquez</strong> pour sélectionner votre CV</p>
              <small>PDF, DOCX, DOC, JPG, PNG — max 5 Mo</small>
            </div>

            <input type="file" id="cv-input-dash" name="cv_fichier" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" style="display:none">

            <div class="cv-preview-box" id="cv-preview-dash">
              <svg id="cv-ico-dash" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--bleu-vif)" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <div>
                <div id="cv-nom-dash"    style="font-weight:500;color:var(--bleu-fonce);font-size:0.87rem;">—</div>
                <div id="cv-taille-dash" style="font-size:0.72rem;color:var(--gris-texte);">—</div>
              </div>
            </div>

            <div class="form-footer">
              <button type="submit" id="btn-upload-dash" class="btn-save" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Envoyer ce CV
              </button>
            </div>
          </form>
        </div>

        <!-- Mise à jour photo de profil -->
        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Photo de profil</div>
              <div class="form-carte-desc">Visible par les recruteurs dans les résultats de recherche</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;">
            <?php if (!empty($candidat['photo'])): ?>
              <img src="../<?= htmlspecialchars($candidat['photo']) ?>" alt="Photo actuelle"
                   style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--bleu-clair);">
            <?php else: ?>
              <div style="width:72px;height:72px;border-radius:50%;background:var(--bleu-pale);border:2px dashed var(--gris-bord);display:flex;align-items:center;justify-content:center;color:var(--gris-texte);">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
            <?php endif; ?>
            <div>
              <div style="font-size:0.86rem;font-weight:500;color:var(--bleu-fonce);margin-bottom:3px;">
                <?= !empty($candidat['photo']) ? 'Photo actuelle' : 'Aucune photo' ?>
              </div>
              <div style="font-size:0.76rem;color:var(--gris-texte);">JPG, PNG, WEBP — max 2 Mo</div>
            </div>
          </div>

          <form method="POST" action="dashboard.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="maj_photo">

            <div class="zone-upload-mini" onclick="document.getElementById('photo-input-dash').click()" style="padding:20px;">
              <div class="zone-upload-mini-ico">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              </div>
              <p><strong>Choisir une nouvelle photo</strong></p>
              <small>JPG, PNG, WEBP — max 2 Mo</small>
            </div>

            <input type="file" id="photo-input-dash" name="photo_profil" accept=".jpg,.jpeg,.png,.webp" style="display:none">

            <div class="cv-preview-box" id="photo-preview-dash">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--vert)" stroke-width="2" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
              <div>
                <div id="photo-nom-dash"    style="font-weight:500;color:var(--bleu-fonce);font-size:0.87rem;">—</div>
                <div id="photo-taille-dash" style="font-size:0.72rem;color:var(--gris-texte);">—</div>
              </div>
            </div>

            <div class="form-footer">
              <button type="submit" id="btn-photo-dash" class="btn-save" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                Mettre à jour la photo
              </button>
            </div>
          </form>
        </div>


      <?php elseif ($onglet === 'securite'): ?>
      <!-- ===
           ONGLET : SÉCURITÉ
    -->

        <div class="securite-info">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Votre compte est protégé par un mot de passe chiffré (bcrypt). Ne partagez jamais votre mot de passe.
        </div>

        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Changer le mot de passe</div>
              <div class="form-carte-desc">Choisissez un mot de passe fort d'au moins 8 caractères</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <form method="POST" action="dashboard.php">
            <input type="hidden" name="action" value="changer_mdp">
            <div class="form-grille">
              <div class="champ-g pleine">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                  Mot de passe actuel
                </label>
                <input type="password" name="mdp_actuel" placeholder="••••••••" required>
              </div>
              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  Nouveau mot de passe
                </label>
                <input type="password" name="mdp_nouveau" placeholder="••••••••" required>
              </div>
              <div class="champ-g">
                <label>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  Confirmer le nouveau mot de passe
                </label>
                <input type="password" name="mdp_confirm" placeholder="••••••••" required>
              </div>
            </div>
            <div class="form-footer">
              <button type="submit" class="btn-save">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                Changer le mot de passe
              </button>
            </div>
          </form>
        </div>

        <div class="form-carte">
          <div class="form-carte-header">
            <div class="form-carte-ico">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
              <div class="form-carte-titre">Informations du compte</div>
              <div class="form-carte-desc">Détails de votre inscription</div>
            </div>
          </div>
          <div class="form-carte-sep"></div>

          <div class="infos-compte">
            <div class="info-compte-item">
              <strong>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                Adresse email
              </strong>
              <span><?= htmlspecialchars($candidat['email']??'') ?></span>
            </div>
            <div class="info-compte-item">
              <strong>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Inscrit le
              </strong>
              <span><?= date('d/m/Y', strtotime($candidat['date_inscription'])) ?></span>
            </div>
            <div class="info-compte-item">
              <strong>
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                Statut
              </strong>
              <span style="color:var(--vert);display:flex;align-items:center;gap:5px;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="20 6 9 17 4 12"/></svg>
                Compte actif
              </span>
            </div>
          </div>
        </div>

      <?php endif; ?>

    </div><!-- .page-body -->
  </main>

<!-- Overlay sombre — ferme la sidebar au clic sur mobile -->
<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

</div><!-- .dashboard-layout -->


<script src="dashboard.js"></script>
</body>
</html>
