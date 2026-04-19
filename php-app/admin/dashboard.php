<?php
require_once '../config/config.php';
require_once '../config/admin_auth.php';
require_once '../config/env_loader.php';          // Charge le fichier .env
require_once '../config/PHPMailer/mailer.php';    // Fonction envoyerEmailSmtp()
demarrerSession();

if (empty($_SESSION['admin_id'])) {
  rediriger('login.php');
}

$admin_id  = $_SESSION['admin_id'];
$admin_nom = $_SESSION['admin_nom'] ?? 'Administrateur';
$pdo       = connexionDB();

define('API_GROQ_URL', getenv('MATCHING_API_URL') ?: 'http://localhost:5001');

$stmtAdmin = $pdo->prepare("SELECT * FROM administrateurs WHERE id = ?");
$stmtAdmin->execute([$admin_id]);
$admin = $stmtAdmin->fetch();

$stats['total_candidats'] = $pdo->query("SELECT COUNT(*) FROM candidats WHERE statut='actif'")->fetchColumn();
$stats['total_cv']        = $pdo->query("SELECT COUNT(*) FROM cv")->fetchColumn();
$stats['cv_analyses']     = $pdo->query("SELECT COUNT(*) FROM cv WHERE statut_analyse='analyse'")->fetchColumn();
$stats['total_recherches']= $pdo->query("SELECT COUNT(*) FROM recherches_admin WHERE admin_id=$admin_id")->fetchColumn();

$stmtHist = $pdo->prepare("SELECT requete_texte, date_recherche FROM recherches_admin WHERE admin_id=? ORDER BY date_recherche DESC LIMIT 5");
$stmtHist->execute([$admin_id]);
$historique = $stmtHist->fetchAll();

$flash = $_SESSION['flash_admin'] ?? null;
unset($_SESSION['flash_admin']);

// Onglet actif — défini ICI avant tout traitement POST 

$onglet = $_GET['onglet'] ?? 'recherche';
if (!in_array($onglet, ['recherche','candidats','historique','parametres'])) $onglet = 'recherche';

// ── Chargement des candidats (toujours, pas seulement si onglet=candidats) ─
// On charge toujours pour éviter "Undefined variable" quelle que soit la page.
$candidats_liste = [];
$stmtC = $pdo->prepare("
  SELECT c.*, cv.statut_analyse, cv.nom_fichier AS cv_nom, cv.chemin AS cv_chemin
  FROM candidats c
  LEFT JOIN cv ON cv.id=(SELECT id FROM cv WHERE candidat_id=c.id ORDER BY date_upload DESC LIMIT 1)
  WHERE c.statut='actif' ORDER BY c.date_inscription DESC
");
$stmtC->execute();
$candidats_liste = $stmtC->fetchAll();

// ─ Envoi email via PHPMailer + SMTP Gmail 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'envoyer_email') {

  $dest     = filter_input(INPUT_POST, 'email_dest', FILTER_SANITIZE_EMAIL);
  $sujet    = nettoyer($_POST['email_sujet']      ?? '');
  $corps    = nettoyer($_POST['email_corps']       ?? '');
  $nom_dest = nettoyer($_POST['candidat_nom_dest'] ?? '');

  if ($dest && $sujet && $corps) {

    // Appel à la fonction dans config/PHPMailer/mailer.php
    // Les identifiants SMTP sont lus depuis le fichier .env
    $resultat = envoyerEmailSmtp(
      $dest,
      $nom_dest,
      $sujet,
      $corps,
      [
        'email' => $admin['email']  ?? '',
        'nom'   => ($admin['prenom'] ?? '') . ' ' . ($admin['nom'] ?? ''),
      ]
    );

    $_SESSION['flash_admin'] = [
      'type'  => $resultat['succes'] ? 'succes' : 'erreur',
      'texte' => $resultat['message'],
    ];

  } else {
    $_SESSION['flash_admin'] = ['type' => 'erreur', 'texte' => 'Remplissez tous les champs du formulaire email.'];
  }

  rediriger('dashboard.php?onglet=candidats');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard Admin — CvMatchIA</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="stylesheet" href="dashboard.css">
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <!-- Lucide Icons — icônes professionnelles légères -->
  <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

</head>
<body>

<div class="layout">

  <!-- SIDEBAR-->
  <aside class="sidebar">

    <div class="sb-logo">
      <a href="../index.php">
        <span>CvMatch</span><span>IA</span>
      </a>
      <div class="sb-role">
        <i data-lucide="shield-check" style="width:11px;height:11px;"></i>
        Espace Administrateur
      </div>
    </div>

    <div class="sb-profil">
      <div class="sb-avatar">
        <?= strtoupper(mb_substr($admin['prenom']??'A',0,1).mb_substr($admin['nom']??'',0,1)) ?>
      </div>
      <div>
        <div class="sb-nom"><?= htmlspecialchars(($admin['prenom']??'').' '.($admin['nom']??'')) ?></div>
        <div class="sb-email"><?= htmlspecialchars($admin['email']??'') ?></div>
      </div>
    </div>

    <nav class="sb-nav">
      <div class="sb-section-label">Navigation</div>

      <a href="dashboard.php?onglet=recherche" class="sb-item <?= $onglet==='recherche'?'actif':'' ?>">
        <span class="sb-ico"><i data-lucide="search" style="width:16px;height:16px;"></i></span>
        Recherche IA
        <span class="sb-badge">IA</span>
      </a>
      <a href="dashboard.php?onglet=candidats" class="sb-item <?= $onglet==='candidats'?'actif':'' ?>">
        <span class="sb-ico"><i data-lucide="users" style="width:16px;height:16px;"></i></span>
        Candidats
        <span class="sb-badge"><?= $stats['total_candidats'] ?></span>
      </a>
      <a href="dashboard.php?onglet=historique" class="sb-item <?= $onglet==='historique'?'actif':'' ?>">
        <span class="sb-ico"><i data-lucide="clock" style="width:16px;height:16px;"></i></span>
        Historique
      </a>
      <a href="dashboard.php?onglet=parametres" class="sb-item <?= $onglet==='parametres'?'actif':'' ?>">
        <span class="sb-ico"><i data-lucide="settings" style="width:16px;height:16px;"></i></span>
        Paramètres
      </a>

      <div class="sb-section-label" style="margin-top:12px;">Contact rapide</div>

      <div class="sb-contact-panel" id="sb-contact-panel">
        <div class="sb-contact-titre">
          <i data-lucide="user-check" style="width:13px;height:13px;"></i>
          Candidat sélectionné
        </div>
        <div class="sb-contact-nom"  id="sb-contact-nom">—</div>
        <div class="sb-contact-info"><i data-lucide="mail" style="width:11px;height:11px;"></i><strong id="sb-contact-email">—</strong></div>
        <div class="sb-contact-info"><i data-lucide="phone" style="width:11px;height:11px;"></i><strong id="sb-contact-tel">—</strong></div>
        <div class="sb-contact-info"><i data-lucide="map-pin" style="width:11px;height:11px;"></i><strong id="sb-contact-ville">—</strong></div>
        <button class="btn-sb-email" id="btn-sb-email-direct" onclick="ouvrirModalSidebar()">
          Envoyer un email
        </button>
        <button class="btn-sb-fermer" onclick="deselectionnerCandidat()">Désélectionner ×</button>
      </div>

      <div id="sb-no-selection" style="padding:7px 10px;">
        <p style="font-size:0.72rem;color:rgba(255,255,255,0.28);font-style:italic;line-height:1.5;">
          Sélectionnez un candidat dans les résultats pour afficher ses coordonnées ici.
        </p>
      </div>
    </nav>

    <div class="sb-footer">
      <a href="logout.php" class="btn-deconnexion">
        <i data-lucide="log-out" style="width:15px;height:15px;"></i>
        Déconnexion
      </a>
    </div>

  </aside>


  <!--  MAIN -->
  <main class="main">

    <div class="topbar">
      <div class="topbar-titre">
        Dashboard Administrateur
        <span class="topbar-badge">CvMatchIA</span>
      </div>
      <div class="topbar-right">
        <span class="topbar-date" id="topbar-date"></span>
        <div class="btn-topbar-icon" title="Notifications">
          <i data-lucide="bell" style="width:16px;height:16px;"></i>
        </div>
      </div>
    </div>

    <div class="page-body">

      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>">
          <?php if ($flash['type']==='succes'): ?>
            <i data-lucide="check-circle" style="width:16px;height:16px;"></i>
          <?php else: ?>
            <i data-lucide="x-circle" style="width:16px;height:16px;"></i>
          <?php endif; ?>
          <?= htmlspecialchars($flash['texte']) ?>
        </div>
      <?php endif; ?>

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-ico bleu"><i data-lucide="users" style="width:22px;height:22px;"></i></div>
          <div><div class="stat-val"><?= $stats['total_candidats'] ?></div><div class="stat-label">Candidats actifs</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-ico vert"><i data-lucide="file-text" style="width:22px;height:22px;"></i></div>
          <div><div class="stat-val"><?= $stats['total_cv'] ?></div><div class="stat-label">CV déposés</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-ico orange"><i data-lucide="cpu" style="width:22px;height:22px;"></i></div>
          <div><div class="stat-val"><?= $stats['cv_analyses'] ?></div><div class="stat-label">CV analysés par IA</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-ico violet"><i data-lucide="zap" style="width:22px;height:22px;"></i></div>
          <div><div class="stat-val">IA</div><div class="stat-label">Moteur actif</div></div>
        </div>
      </div>


      <?php if ($onglet === 'recherche'): ?>
      <!-- RECHERCHE IA -->

      <div class="recherche-layout" id="recherche-layout">

        <!-- Panneau gauche : saisie -->
        <div class="panneau-recherche">

          <div class="panneau-titre">
            <i data-lucide="search" style="width:18px;height:18px;color:var(--bleu-vif);"></i>
            Recherche intelligente
            <span class="groq-badge"></span>
          </div>
          <p class="panneau-desc">
            Décrivez le profil en langage naturel ou posez une question —
            l'IA détecte automatiquement votre intention.
          </p>

          <div class="recherche-zone">
            <textarea id="requete-input" class="recherche-input"
              placeholder="Ex : Je cherche une femme informaticienne avec 2 ans d'expérience à Abidjan…&#10;Ou : Combien de candidats avons-nous ?&#10;"
              maxlength="500"></textarea>
            <div class="recherche-toolbar">
              <div style="display:flex;align-items:center;gap:9px;">
                <button class="btn-micro" id="btn-micro" onclick="toggleVocal()" title="Dicter">
                  <i data-lucide="mic" style="width:14px;height:14px;"></i>
                </button>
                <span class="vocal-statut" id="vocal-statut">Cliquez pour dicter</span>
              </div>
              <span class="nb-chars" id="nb-chars">0 / 500</span>
            </div>
          </div>

          <div class="suggestions">
            <span class="suggestion-chip" onclick="remplirSuggestion(this)">Femme informaticienne · 1 an · Abidjan</span>
            <span class="suggestion-chip" onclick="remplirSuggestion(this)">Data scientist · Python · 3+ ans</span>
            <span class="suggestion-chip" onclick="remplirSuggestion(this)">Dev Full Stack · disponible immédiatement</span>
            <span class="suggestion-chip" onclick="remplirSuggestion(this)">Combien de candidats ?</span>
            <span class="suggestion-chip" onclick="remplirSuggestion(this)">Profil junior · sorti d'école</span>
          </div>

          <button class="btn-analyser" id="btn-analyser" onclick="lancerAnalyse()">
            <i data-lucide="sparkles" style="width:17px;height:17px;"></i>
            <span id="analyser-texte">Analyser</span>
          </button>

          <!-- Agent conversationnel -->
          <div class="agent-section" id="agent-section" style="display:none;">
            <div class="agent-titre">
              <i data-lucide="message-square" style="width:14px;height:14px;"></i>
              Agent IA — Affinez votre recherche
            </div>
            <div class="agent-chat" id="agent-chat"></div>
            <div class="agent-input-row">
              <input type="text" class="agent-input" id="agent-input"
                placeholder="Ex : Seulement les femmes de moins de 30 ans…"
                onkeydown="if(event.key==='Enter') envoyerAgent()">
              <button class="btn-send" onclick="envoyerAgent()">
                <i data-lucide="send" style="width:14px;height:14px;"></i>
              </button>
            </div>
          </div>

          <?php if (!empty($historique)): ?>
          <div class="historique-rapide">
            <div class="hist-titre">Recherches récentes</div>
            <?php foreach ($historique as $h): ?>
              <div class="hist-item" onclick="chargerHistorique(this)"
                   data-requete="<?= htmlspecialchars($h['requete_texte']) ?>">
                <i data-lucide="history" style="width:12px;height:12px;flex-shrink:0;color:var(--gris-texte);"></i>
                <span class="hist-item-texte"><?= htmlspecialchars(mb_substr($h['requete_texte'],0,52)) ?>…</span>
                <span class="hist-date"><?= date('d/m', strtotime($h['date_recherche'])) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

        </div>

        <!-- Panneau droit : résultats -->
        <div class="panneau-resultats" id="panneau-resultats">

          <div class="resultats-header" id="resultats-header" style="display:none;">
            <div>
              <div class="resultats-nb" id="resultats-nb">0 résultats</div>
              <div style="font-size:0.73rem;color:var(--gris-texte);margin-top:3px;" id="resultats-requete-label"></div>
              <code class="sql-tag" id="sql-tag" style="display:none;"></code>
            </div>
            <div class="resultats-tri">
              <button class="tri-btn actif" onclick="trierResultats('score',this)">Score</button>
              <button class="tri-btn"       onclick="trierResultats('nom',this)">Nom</button>
              <button class="tri-btn"       onclick="trierResultats('exp',this)">Expérience</button>
            </div>
          </div>

          <?php for($i=0;$i<3;$i++): ?>
          <div class="skeleton-card" id="skel-<?=$i?>">
            <div style="display:flex;gap:14px;margin-bottom:12px;">
              <div class="skel-line" style="width:58px;height:58px;border-radius:50%;flex-shrink:0;margin-bottom:0;"></div>
              <div style="flex:1;"><div class="skel-line" style="width:55%;"></div><div class="skel-line" style="width:38%;"></div></div>
            </div>
            <div class="skel-line" style="width:88%;"></div><div class="skel-line" style="width:70%;"></div>
          </div>
          <?php endfor; ?>

          <div class="etat-vide" id="etat-vide">
            <div class="etat-vide-ico"><i data-lucide="search" style="width:40px;height:40px;opacity:0.3;"></i></div>
            <div class="etat-vide-titre">Prêt pour l'analyse</div>
            <div class="etat-vide-desc">Saisissez votre requête à gauche et cliquez sur <strong>Analyser</strong>.<br>Vous pouvez aussi simplement écrire « Bonjour » pour commencer.</div>
          </div>

          <div id="resultats-conteneur"></div>

        </div>
      </div>


      <?php elseif ($onglet === 'candidats'): ?>
      <!--  TOUS LES CANDIDATS  -->

      <div class="table-wrap">
        <div class="table-header-row">
          <h3><i data-lucide="users" style="width:17px;height:17px;"></i> Tous les candidats (<?= count($candidats_liste) ?>)</h3>
        </div>
        <table>
          <thead>
            <tr>
              <th></th>
              <th>Candidat</th>
              <th>Ville</th>
              <th>Poste</th>
              <th>Expérience</th>
              <th>CV</th>
              <th>Inscrit le</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($candidats_liste)): ?>
              <tr><td colspan="8" style="text-align:center;color:var(--gris-texte);padding:40px;">Aucun candidat inscrit.</td></tr>
            <?php else: ?>
              <?php foreach ($candidats_liste as $c): ?>
              <tr>
                <td style="width:50px;">
                  <?php if (!empty($c['photo'])): ?>
                    <img src="../<?= htmlspecialchars($c['photo']) ?>" class="td-avatar" alt="">
                  <?php else: ?>
                    <div class="td-avatar-fb">
                      <?= strtoupper(mb_substr($c['prenom']??'?',0,1).mb_substr($c['nom']??'',0,1)) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td class="td-nom">
                  <strong><?= htmlspecialchars($c['prenom'].' '.$c['nom']) ?></strong>
                  <small><?= htmlspecialchars($c['email']) ?></small>
                </td>
                <td><?= htmlspecialchars($c['ville'] ?? '—') ?></td>
                <td><?= htmlspecialchars($c['poste_actuel'] ?? '—') ?></td>
                <td><?= $c['experience_ans'] ? $c['experience_ans'].' ans' : '—' ?></td>
                <td>
                  <?php if ($c['cv_nom']): ?>
                    <span class="<?= $c['statut_analyse']==='analyse' ? 'sp-analyse' : 'sp-attente' ?>">
                      <?= $c['statut_analyse']==='analyse' ? 'Analysé' : 'En attente' ?>
                    </span>
                  <?php else: ?>
                    <span class="sp-no-cv">Pas de CV</span>
                  <?php endif; ?>
                </td>
                <td><?= date('d/m/Y', strtotime($c['date_inscription'])) ?></td>
                <td>
                  <button class="btn-td btn-td-email"
                    onclick="ouvrirModal('<?= htmlspecialchars($c['email']) ?>','<?= htmlspecialchars($c['prenom'].' '.$c['nom']) ?>')">
                    <i data-lucide="mail" style="width:12px;height:12px;"></i> Email
                  </button>
                  <?php if (!empty($c['cv_chemin'])): ?>
                  <a href="../<?= htmlspecialchars($c['cv_chemin']) ?>" download="<?= htmlspecialchars($c['cv_nom'] ?? 'cv') ?>" class="btn-td btn-td-dl">
                    <i data-lucide="download" style="width:12px;height:12px;"></i> CV
                  </a>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>


      <?php elseif ($onglet === 'historique'): ?>
      <!-- HISTORIQUE  -->

      <div class="table-wrap">
        <div class="table-header-row">
          <h3><i data-lucide="clock" style="width:17px;height:17px;"></i> Historique des recherches</h3>
        </div>
        <table>
          <thead><tr><th>Requête</th><th>Date</th></tr></thead>
          <tbody>
            <?php
            $stmtAll = $pdo->prepare("SELECT * FROM recherches_admin WHERE admin_id=? ORDER BY date_recherche DESC LIMIT 50");
            $stmtAll->execute([$admin_id]);
            $toutes = $stmtAll->fetchAll();
            foreach ($toutes as $h):
            ?>
              <tr>
                <td><?= htmlspecialchars($h['requete_texte']) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($h['date_recherche'])) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($toutes)): ?>
              <tr><td colspan="2" style="text-align:center;color:var(--gris-texte);padding:40px;">Aucune recherche effectuée.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>


      <?php elseif ($onglet === 'parametres'): ?>
      <!-- PARAMÈTRES  -->

      <div style="background:var(--blanc);border-radius:16px;padding:28px;box-shadow:var(--ombre);">
        <h3 style="font-family:var(--font-titre);font-size:1rem;font-weight:700;color:var(--bleu-fonce);margin-bottom:18px;display:flex;align-items:center;gap:8px;">
          <i data-lucide="settings" style="width:18px;height:18px;"></i> Paramètres du compte
        </h3>
        <p style="font-size:0.86rem;color:var(--gris-texte);margin-bottom:8px;">
          Compte : <strong style="color:var(--bleu-fonce)"><?= htmlspecialchars($admin['email']??'') ?></strong>
        </p>
        <p style="font-size:0.86rem;color:var(--gris-texte);margin-bottom:8px;">
          Moteur IA : <strong style="color:var(--bleu-fonce)"> —</strong>
        </p>
        <p style="font-size:0.86rem;color:var(--gris-texte);margin-bottom:8px;">
          API Python : <code style="background:var(--gris-fond);padding:2px 8px;border-radius:5px;font-size:0.82rem;"><?= API_GROQ_URL ?></code>
        </p>
        <p style="font-size:0.86rem;color:var(--gris-texte);">
          Face ID : <strong style="color:<?= ($admin['face_id_actif']??0)?'var(--vert)':'var(--orange)' ?>">
            <?= ($admin['face_id_actif']??0) ? 'Activé' : 'Non configuré' ?>
          </strong>
        </p>
      </div>

      <?php endif; ?>

    </div><!-- page-body -->
  </main>
</div><!-- layout -->


<!-- MODAL EMAIL-->
<div class="modal-overlay" id="modal-email">
  <div class="modal">
    <div class="modal-titre">
      <i data-lucide="send" style="width:18px;height:18px;color:var(--bleu-vif);"></i>
      Contacter un candidat
    </div>
    <div class="modal-dest">
      À : <strong id="modal-dest-nom">—</strong>
      &lt;<span id="modal-dest-email" style="color:var(--gris-texte);">—</span>&gt;
    </div>

    <form method="POST" action="dashboard.php?onglet=candidats">
      <input type="hidden" name="action"           value="envoyer_email">
      <input type="hidden" name="email_dest"        id="input-email-dest">
      <input type="hidden" name="candidat_nom_dest" id="input-nom-dest">

      <div class="modal-champ">
        <label>Sujet</label>
        <select name="email_sujet">
          <option>Votre candidature sur CvMatchIA</option>
          <option>Invitation à un entretien</option>
          <option>Demande d'informations complémentaires</option>
          <option>Suite donnée à votre candidature</option>
        </select>
      </div>
      <div class="modal-champ">
        <label>Message</label>
        <textarea name="email_corps" id="modal-corps" placeholder="Rédigez votre message…"></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-annuler" onclick="fermerModal()">Annuler</button>
        <button type="submit" class="btn-envoyer">
          <i data-lucide="send" style="width:13px;height:13px;margin-right:5px;"></i>Envoyer
        </button>
      </div>
    </form>
  </div>
</div>


<!--  JAVASCRIPt -->
<script>
// Initialiser les icônes Lucide
lucide.createIcons();

const API_URL = '<?= API_GROQ_URL ?>';
let resultatsActuels  = [];
let candidatSelectionne = null;

//  Date topbar
document.getElementById('topbar-date').textContent =
  new Date().toLocaleDateString('fr-FR', {day:'numeric',month:'long',year:'numeric'});

// Compteur textarea 
document.getElementById('requete-input')?.addEventListener('input', function() {
  document.getElementById('nb-chars').textContent = this.value.length + ' / 500';
});

//  LANCER L'ANALYSE
async function lancerAnalyse() {
  const requete = document.getElementById('requete-input').value.trim();
  if (!requete) { alert('Veuillez saisir une requête.'); return; }

  const btn = document.getElementById('btn-analyser');
  btn.disabled = true;
  document.getElementById('analyser-texte').textContent = 'Analyse en cours…';

  // Afficher skeletons
  document.getElementById('etat-vide').style.display        = 'none';
  document.getElementById('resultats-header').style.display = 'none';
  document.getElementById('resultats-conteneur').innerHTML  = '';
  for (let i=0;i<3;i++) document.getElementById(`skel-${i}`).classList.add('visible');

  try {
    const resp = await fetch(`${API_URL}/ask`, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ question: requete, recruteur_id: <?= $admin_id ?> })
    });

    if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data = await resp.json();

    for (let i=0;i<3;i++) document.getElementById(`skel-${i}`).classList.remove('visible');

    // Sauvegarder la recherche
    fetch('save_recherche.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ requete, admin_id: <?= $admin_id ?> })
    }).catch(() => {});

    // Afficher selon le type de réponse
    if (data.type === 'conversation' || data.type === 'statistique') {
      afficherReponseTexte(data.reponse, data.type);
    } else {
      // Recherche avec résultats
      resultatsActuels = data.results || [];
      afficherResultats(resultatsActuels, data.sql_generated, requete);
    }

    // Toujours afficher l'agent après une réponse
    document.getElementById('agent-section').style.display = 'block';
    ajouterMsgIA(data.reponse || '');

  } catch (err) {
    for (let i=0;i<3;i++) document.getElementById(`skel-${i}`).classList.remove('visible');
    document.getElementById('etat-vide').style.display = 'block';
    ajouterMsgIA(`Impossible de contacter le service Groq. Vérifiez que le serveur Python tourne sur le port 5001. (${err.message})`);
    document.getElementById('agent-section').style.display = 'block';
  }

  btn.disabled = false;
  document.getElementById('analyser-texte').textContent = 'Analyser';
}

//  Afficher réponse conversation / statistique 
function afficherReponseTexte(texte, type) {
  const header    = document.getElementById('resultats-header');
  const conteneur = document.getElementById('resultats-conteneur');
  const etatVide  = document.getElementById('etat-vide');

  etatVide.style.display  = 'none';
  header.style.display    = 'none';

  const classe = type === 'statistique' ? 'alerte-stat-bg' : '';
  const icone  = type === 'statistique'
    ? '<i data-lucide="bar-chart-2" style="width:20px;height:20px;"></i>'
    : '<i data-lucide="message-circle" style="width:20px;height:20px;"></i>';

  conteneur.innerHTML = `
    <div class="alerte-conversation ${classe}">
      <div class="alerte-conv-ico">${icone}</div>
      <div class="alerte-conv-texte">${escHtml(texte)}</div>
    </div>`;

  lucide.createIcons(); // re-initialiser les icônes injectées
}

//  Afficher les cartes résultats 
function afficherResultats(resultats, sql, requete) {
  const header    = document.getElementById('resultats-header');
  const conteneur = document.getElementById('resultats-conteneur');
  const etatVide  = document.getElementById('etat-vide');

  header.style.display = 'flex';
  etatVide.style.display = 'none';

  const nb = resultats.length;
  document.getElementById('resultats-nb').innerHTML = `<span>${nb}</span> profil${nb>1?'s':''} trouvé${nb>1?'s':''}`;
  document.getElementById('resultats-requete-label').textContent = `"${(requete||'').substring(0,70)}${requete&&requete.length>70?'…':''}"`;

  const sqlTag = document.getElementById('sql-tag');
  if (sql) { sqlTag.textContent = sql; sqlTag.style.display = 'block'; }
  else       { sqlTag.style.display = 'none'; }

  if (nb === 0) {
    etatVide.style.display = 'block';
    document.getElementById('etat-vide').querySelector('.etat-vide-titre').textContent = 'Aucun résultat';
    document.getElementById('etat-vide').querySelector('.etat-vide-desc').textContent  = 'Essayez des critères plus larges.';
    return;
  }

  conteneur.innerHTML = resultats.map((c, i) => construireCarte(c, i)).join('');
  lucide.createIcons();
}

// Construire le HTML d'une carte candidat 
function construireCarte(c, idx) {
  const rang      = idx + 1;
  const barClass  = rang===1 ? 'or' : rang===2 ? 'argent' : rang===3 ? 'bronze' : '';
  const score     = c.score || 0;
  const rayon     = 22;
  const circonf   = 2 * Math.PI * rayon;
  const offset    = circonf - (score / 100) * circonf;
  const couleur   = score>=80 ? '#38A169' : score>=60 ? '#1D6FEB' : '#D97706';
  const initiales = ((c.prenom||c.nom||'?')[0] + (c.nom||'?')[c.nom?0:1]||'?').toUpperCase();
  const photoHtml = c.photo
    ? `<img class="card-photo" src="../uploads/photos/${escHtml(c.photo)}" alt="${escHtml(c.prenom||'')} ${escHtml(c.nom||'')}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><div class="card-photo-fallback" style="display:none">${initiales}</div>`
    : `<div class="card-photo-fallback">${initiales}</div>`;

  const tags = (c.competences||'').split(',').map(t=>t.trim()).filter(Boolean).slice(0,5);
  const tagsHtml = tags.map(t=>`<span class="card-tag match">${escHtml(t)}</span>`).join('');

  const nom   = escHtml(`${c.prenom||''} ${c.nom||''}`.trim());
  const poste = escHtml(c.poste_actuel || c.profession || 'Poste non renseigné');
  const ville = escHtml(c.ville || '—');
  const exp   = c.experience_ans ? `${c.experience_ans} ans d'exp.` : '';

  const dlBouton = c.cv_chemin
    ? `<button class="btn-action btn-download" onclick="event.stopPropagation();telechargerCV('${escHtml(c.cv_chemin)}','${escHtml(c.cv_nom||'cv')}')">
         <i data-lucide="download" style="width:13px;height:13px;"></i> CV
       </button>`
    : `<button class="btn-action btn-download" disabled style="opacity:.4;cursor:not-allowed;">
         <i data-lucide="file-x" style="width:13px;height:13px;"></i> Pas de CV
       </button>`;

  return `
  <div class="candidat-card" id="card-${c.id}"
       onclick="selectionnerCandidat(${c.id},'${nom}','${escHtml(c.email||'')}','${escHtml(c.telephone||'')}','${escHtml(c.ville||'')}')">

    <div class="card-rang-bar ${barClass}"></div>
    <div class="card-body">
      <div class="card-header">
        <div style="flex-shrink:0">${photoHtml}</div>
        <div class="card-infos">
          <div class="card-nom">${nom}</div>
          <div class="card-poste">${poste}</div>
          <div class="card-meta">
            ${ville !== '—' ? `<span class="card-meta-item"><i data-lucide="map-pin" style="width:11px;height:11px;"></i>${ville}</span>` : ''}
            ${exp            ? `<span class="card-meta-item"><i data-lucide="briefcase" style="width:11px;height:11px;"></i>${exp}</span>` : ''}
            ${c.disponibilite==='immediat' ? '<span class="card-meta-item" style="color:var(--vert);"><i data-lucide="check-circle" style="width:11px;height:11px;"></i>Disponible</span>' : ''}
          </div>
        </div>
        <!-- Anneau SVG score -->
        <div class="score-anneau">
          <svg width="56" height="56" viewBox="0 0 56 56">
            <circle cx="28" cy="28" r="${rayon}" fill="none" stroke="#E8F0FE" stroke-width="5"/>
            <circle cx="28" cy="28" r="${rayon}" fill="none" stroke="${couleur}"
              stroke-width="5" stroke-dasharray="${circonf.toFixed(1)}"
              stroke-dashoffset="${offset.toFixed(1)}" stroke-linecap="round"
              transform="rotate(-90 28 28)"/>
            <text x="28" y="33" text-anchor="middle" font-family="Syne,sans-serif"
              font-size="11" font-weight="700" fill="${couleur}">${score}%</text>
          </svg>
          <span class="score-label">Match</span>
        </div>
      </div>

      ${tagsHtml ? `<div class="card-tags">${tagsHtml}</div>` : ''}

      ${c.resume_ia ? `
      <div class="card-resume">
        <div class="card-resume-label">
          <i data-lucide="sparkles" style="width:10px;height:10px;"></i> Résumé IA
        </div>
        ${escHtml(c.resume_ia)}
      </div>` : ''}
    </div>

    <div class="card-footer">
      <button class="btn-action btn-contact"
        onclick="event.stopPropagation();ouvrirModal('${escHtml(c.email||'')}','${nom}')">
        <i data-lucide="mail" style="width:13px;height:13px;"></i> Contacter
      </button>
      ${dlBouton}
      <button class="btn-action btn-select" id="btn-sel-${c.id}"
        onclick="event.stopPropagation();selectionnerCandidat(${c.id},'${nom}','${escHtml(c.email||'')}','${escHtml(c.telephone||'')}','${escHtml(c.ville||'')}')">
        <i data-lucide="user-check" style="width:13px;height:13px;"></i>
      </button>
    </div>
  </div>`;
}

// Téléchargement du CV 
function telechargerCV(chemin, nom) {
  const a = document.createElement('a');
  a.href     = `../${chemin}`;
  a.download = nom;
  a.click();
}

//  Tri des résultats 
function trierResultats(critere, btn) {
  document.querySelectorAll('.tri-btn').forEach(b => b.classList.remove('actif'));
  btn.classList.add('actif');
  const t = [...resultatsActuels];
  if (critere === 'score') t.sort((a,b) => (b.score||0)-(a.score||0));
  if (critere === 'nom')   t.sort((a,b) => `${a.prenom||''} ${a.nom||''}`.localeCompare(`${b.prenom||''} ${b.nom||''}`));
  if (critere === 'exp')   t.sort((a,b) => (b.experience_ans||0)-(a.experience_ans||0));
  document.getElementById('resultats-conteneur').innerHTML = t.map((c,i) => construireCarte(c,i)).join('');
  lucide.createIcons();
}

//  AGENT CONVERSATIONNEL
async function envoyerAgent() {
  const input    = document.getElementById('agent-input');
  const question = input.value.trim();
  if (!question) return;

  ajouterMsgUser(question);
  input.value = '';

  try {
    const resp = await fetch(`${API_URL}/ask`, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({
        question,
        recruteur_id: <?= $admin_id ?>,
        historique_chat: obtenirHistoriqueChat()
      })
    });
    const data = await resp.json();
    ajouterMsgIA(data.reponse || '');
    if (data.results && data.results.length > 0) {
      resultatsActuels = data.results;
      afficherResultats(resultatsActuels, data.sql_generated, question);
    }
  } catch(e) {
    ajouterMsgIA(`Erreur : ${e.message}`);
  }
}

function ajouterMsgUser(texte) {
  const chat = document.getElementById('agent-chat');
  chat.insertAdjacentHTML('beforeend', `<div class="msg-user">${escHtml(texte)}</div>`);
  chat.scrollTop = chat.scrollHeight;
}

function ajouterMsgIA(texte) {
  const chat = document.getElementById('agent-chat');
  chat.insertAdjacentHTML('beforeend', `<div class="msg-ia">${escHtml(texte)}</div>`);
  chat.scrollTop = chat.scrollHeight;
}

function obtenirHistoriqueChat() {
  const messages = [];
  document.querySelectorAll('#agent-chat > div').forEach(div => {
    messages.push({
      role:    div.classList.contains('msg-user') ? 'user' : 'assistant',
      content: div.textContent,
    });
  });
  return messages.slice(-6);
}


//  SÉLECTION CANDIDAT (sidebar)
function selectionnerCandidat(id, nom, email, tel, ville) {
  // Désélectionner l'ancien
  if (candidatSelectionne) {
    document.getElementById(`card-${candidatSelectionne}`)?.classList.remove('selectionne');
    document.getElementById(`btn-sel-${candidatSelectionne}`)?.classList.remove('actif');
  }
  candidatSelectionne = id;
  document.getElementById(`card-${id}`)?.classList.add('selectionne');
  document.getElementById(`btn-sel-${id}`)?.classList.add('actif');

  document.getElementById('sb-contact-nom').textContent   = nom;
  document.getElementById('sb-contact-email').textContent = email || '—';
  document.getElementById('sb-contact-tel').textContent   = tel   || '—';
  document.getElementById('sb-contact-ville').textContent = ville || '—';
  document.getElementById('sb-contact-panel').classList.add('visible');
  document.getElementById('sb-no-selection').style.display = 'none';
}

function deselectionnerCandidat() {
  if (candidatSelectionne) {
    document.getElementById(`card-${candidatSelectionne}`)?.classList.remove('selectionne');
    document.getElementById(`btn-sel-${candidatSelectionne}`)?.classList.remove('actif');
    candidatSelectionne = null;
  }
  document.getElementById('sb-contact-panel').classList.remove('visible');
  document.getElementById('sb-no-selection').style.display = 'block';
}

function ouvrirModalSidebar() {
  if (!candidatSelectionne) return;
  const email = document.getElementById('sb-contact-email').textContent;
  const nom   = document.getElementById('sb-contact-nom').textContent;
  ouvrirModal(email, nom);
}


//  MODAL EMAIL
function ouvrirModal(email, nom) {
  document.getElementById('modal-dest-nom').textContent   = nom;
  document.getElementById('modal-dest-email').textContent = email;
  document.getElementById('input-email-dest').value       = email;
  document.getElementById('input-nom-dest').value         = nom;
  document.getElementById('modal-corps').value =
    `Bonjour ${nom.split(' ')[0]},\n\nNous avons bien reçu votre candidature et souhaitons vous contacter.\n\nCordialement,\n<?= htmlspecialchars($admin_nom) ?>`;
  document.getElementById('modal-email').classList.add('visible');
}

function fermerModal() {
  document.getElementById('modal-email').classList.remove('visible');
}

document.getElementById('modal-email').addEventListener('click', function(e) {
  if (e.target === this) fermerModal();
});

//  RECONNAISSANCE VOCALE
let vocaleActive = false;

function toggleVocal() {
  const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRec) { alert('Reconnaissance vocale non supportée par ce navigateur.'); return; }

  if (vocaleActive) return;
  const rec   = new SpeechRec();
  rec.lang    = 'fr-FR';
  rec.onstart = () => {
    vocaleActive = true;
    document.getElementById('btn-micro').classList.add('ecoute');
    document.getElementById('vocal-statut').textContent = 'Écoute…';
    document.getElementById('vocal-statut').classList.add('ecoute');
  };
  rec.onresult = e => {
    document.getElementById('requete-input').value = e.results[0][0].transcript;
    document.getElementById('nb-chars').textContent = document.getElementById('requete-input').value.length + ' / 500';
  };
  rec.onend = () => {
    vocaleActive = false;
    document.getElementById('btn-micro').classList.remove('ecoute');
    document.getElementById('vocal-statut').textContent = 'Cliquez pour dicter';
    document.getElementById('vocal-statut').classList.remove('ecoute');
  };
  rec.start();
}

//  SUGGESTIONS + HISTORIQUE
function remplirSuggestion(el) {
  document.getElementById('requete-input').value = el.textContent.trim();
  document.getElementById('nb-chars').textContent = el.textContent.trim().length + ' / 500';
}

function chargerHistorique(el) {
  document.getElementById('requete-input').value = el.dataset.requete;
  document.getElementById('nb-chars').textContent = el.dataset.requete.length + ' / 500';
  lancerAnalyse();
}

//  Utilitaire anti-XSS 
function escHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>

</body>
</html>
