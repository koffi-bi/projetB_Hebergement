// Animation hero image 
  window.addEventListener('load', () => {
    document.getElementById('hero-image').classList.add('charge');
  });

  //  Gestion des Steps 
  let stepActuel = 1;

  function allerStep(num) {
    // Cacher le step actuel
    document.getElementById('step-' + stepActuel).classList.remove('actif');
    document.getElementById('ind-' + stepActuel).classList.remove('actif');

    // Marquer comme fait si on avance
    if (num > stepActuel) {
      document.getElementById('ind-' + stepActuel).classList.add('fait');
    } else {
      // Si on revient, retirer "fait" du step suivant
      document.getElementById('ind-' + num).classList.remove('fait');
    }

    // Afficher le nouveau step
    stepActuel = num;
    document.getElementById('step-' + stepActuel).classList.add('actif');
    document.getElementById('ind-' + stepActuel).classList.add('actif');

    // Scroll vers le haut de la section steps
    document.getElementById('steps').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // Au chargement du document
document.addEventListener('DOMContentLoaded', () => {
    // On récupère les paramètres dans l'URL (ex: ?step=2)
    const urlParams = new URLSearchParams(window.location.search);
    const stepParticulier = urlParams.get('step');

    if (stepParticulier) {
        // Si un step est précisé dans l'URL, on y va
        allerStep(parseInt(stepParticulier));
    }
});

// ── Upload CV : aperçu du fichier ──
  const inputCv      = document.getElementById('input-cv');
  const btnUpload    = document.getElementById('btn-upload');
  const preview      = document.getElementById('fichier-preview');
  const ficNom       = document.getElementById('fichier-nom');
  const ficTaille    = document.getElementById('fichier-taille');
  const ficIcone     = document.getElementById('fichier-icone');

  // Icônes selon l'extension
  const iconesExt = { pdf:'📕', doc:'📘', docx:'📘', jpg:'🖼️', jpeg:'🖼️', png:'🖼️' };

  // Suivi de l'état (CV sélectionné ou non)
  let cvSelectionne = false;

  inputCv.addEventListener('change', function() {
    if (this.files.length > 0) {
      afficherApercu(this.files[0]);
    }
  });

  function afficherApercu(fichier) {
    const ext = fichier.name.split('.').pop().toLowerCase();
    ficIcone.textContent  = iconesExt[ext] || '📄';
    ficNom.textContent    = fichier.name;
    ficTaille.textContent = (fichier.size / 1024).toFixed(0) + ' Ko';
    preview.classList.add('visible');
    cvSelectionne = true;
    mettreAJourRecap(fichier.name, null);
    verifierPretAEnvoyer();
  }

  // Drag & drop CV
  function survol(e) {
    e.preventDefault();
    document.getElementById('zone-drop').classList.add('survol');
  }
  function finSurvol() {
    document.getElementById('zone-drop').classList.remove('survol');
  }
  function deposer(e) {
    e.preventDefault();
    finSurvol();
    const fichier = e.dataTransfer.files[0];
    if (fichier) {
      const dt = new DataTransfer();
      dt.items.add(fichier);
      inputCv.files = dt.files;
      afficherApercu(fichier);
    }
  }

  // ── Upload Photo : prévisualisation instantanée 
  const inputPhoto   = document.getElementById('input-photo');
  const photoImg     = document.getElementById('photo-preview-img');
  const photoPlaceH  = document.getElementById('photo-placeholder');
  const photoCercle  = document.getElementById('photo-cercle');
  const photoStatut  = document.getElementById('photo-statut');

  let photoSelectionnee = false;

  inputPhoto.addEventListener('change', function() {
    const fichier = this.files[0];
    if (!fichier) return;

    // Vérifications côté JS (double sécurité avant le PHP)
    const ext    = fichier.name.split('.').pop().toLowerCase();
    const taille = fichier.size / 1024;

    if (!['jpg','jpeg','png','webp'].includes(ext)) {
      photoStatut.innerHTML = '<span style="color:var(--rouge);">❌ Format non autorisé (JPG, PNG, WEBP)</span>';
      this.value = '';
      return;
    }
    if (taille > 2048) {
      photoStatut.innerHTML = '<span style="color:var(--rouge);">❌ Trop lourd (max 2 Mo)</span>';
      this.value = '';
      return;
    }

    // Afficher la prévisualisation dans le cercle
    const reader = new FileReader();
    reader.onload = (e) => {
      photoImg.src          = e.target.result;
      photoImg.style.display = 'block';
      photoPlaceH.style.display = 'none';
      photoCercle.style.borderStyle = 'solid';
      photoCercle.style.borderColor = 'var(--vert)';
    };
    reader.readAsDataURL(fichier);

    photoSelectionnee = true;
    photoStatut.innerHTML = '<span style="color:var(--vert);">✅ ' + fichier.name + '</span><br><span style="color:#94A3B8;">' + taille.toFixed(0) + ' Ko</span>';
    mettreAJourRecap(null, fichier.name);
  });

  //  Récapitulatif avant envoi 
  function mettreAJourRecap(nomCv, nomPhoto) {
    const recap        = document.getElementById('recap-upload');
    const recapCvLine  = document.getElementById('recap-cv-line');
    const recapPhLine  = document.getElementById('recap-photo-line');

    if (nomCv) {
      document.getElementById('recap-cv-nom').textContent = nomCv;
      recapCvLine.style.display = 'block';
    }
    if (nomPhoto) {
      document.getElementById('recap-photo-nom').textContent = nomPhoto;
      recapPhLine.style.display = 'block';
    }

    // Afficher le récap si au moins un fichier prêt
    if (recapCvLine.style.display === 'block' || recapPhLine.style.display === 'block') {
      recap.style.display = 'block';
    }
  }

  // ── Activer le bouton Envoyer dès qu'un CV est choisi ─
  function verifierPretAEnvoyer() {
    btnUpload.disabled = !cvSelectionne;
  }

  // On récupère la valeur du flag injecté par PHP
const cvExistantFlag = document.getElementById('cv_existant_flag');

if (cvExistantFlag && cvExistantFlag.value === '1') {
    // CV déjà en base → on active l'envoi même sans nouveau fichier chargé
    if (btnUpload) btnUpload.disabled = false;
    cvSelectionne = true;
}