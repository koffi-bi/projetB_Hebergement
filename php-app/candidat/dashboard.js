// ── Upload CV : aperçu du fichier sélectionné ─────────────
const cvInputDash  = document.getElementById('cv-input-dash');
const btnUploadDash = document.getElementById('btn-upload-dash');
const cvPreview    = document.getElementById('cv-preview-dash');

if (cvInputDash) {
  cvInputDash.addEventListener('change', function() {
    if (this.files[0]) afficherCV(this.files[0]);
  });
}

function afficherCV(fichier) {
  document.getElementById('cv-nom-dash').textContent    = fichier.name;
  document.getElementById('cv-taille-dash').textContent = (fichier.size / 1024).toFixed(0) + ' Ko';
  cvPreview.classList.add('visible');
  btnUploadDash.disabled = false;
}

function dropCV(e) {
  e.preventDefault();
  const f = e.dataTransfer.files[0];
  if (!f) return;
  const dt = new DataTransfer(); dt.items.add(f);
  cvInputDash.files = dt.files;
  afficherCV(f);
}

// ── Upload Photo : aperçu ─────────────────────────────────
const photoInput   = document.getElementById('photo-input-dash');
const btnPhotoDash = document.getElementById('btn-photo-dash');
const photoPreview = document.getElementById('photo-preview-dash');

if (photoInput) {
  photoInput.addEventListener('change', function() {
    if (this.files[0]) {
      const f = this.files[0];
      document.getElementById('photo-nom-dash').textContent    = f.name;
      document.getElementById('photo-taille-dash').textContent = (f.size / 1024).toFixed(0) + ' Ko';
      photoPreview.classList.add('visible');
      btnPhotoDash.disabled = false;
    }
  });
}

//  HAMBURGER MENU — ouvrir/fermer la sidebar sur mobil

const sidebar   = document.querySelector('.sidebar');
const overlay   = document.getElementById('sidebar-overlay');
const hamburger = document.getElementById('btn-hamburger');

/**
 * Bascule la sidebar : slide depuis la gauche sur mobile.
 */
function toggleSidebar() {
  const estOuverte = sidebar.classList.toggle('ouverte');
  overlay.classList.toggle('visible', estOuverte);

  // Changer l'icône : hamburger ↔ croix
  hamburger.innerHTML = estOuverte
    ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
         <line x1="18" y1="6" x2="6" y2="18"/>
         <line x1="6" y1="6" x2="18" y2="18"/>
       </svg>`
    : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
         <line x1="3" y1="6"  x2="21" y2="6"/>
         <line x1="3" y1="12" x2="21" y2="12"/>
         <line x1="3" y1="18" x2="21" y2="18"/>
       </svg>`;
}

// Fermer la sidebar quand on clique sur un lien de navigation (mobile)
document.querySelectorAll('.nav-item').forEach(lien => {
  lien.addEventListener('click', () => {
    if (window.innerWidth <= 900) {
      sidebar.classList.remove('ouverte');
      overlay.classList.remove('visible');
    }
  });
});

// Fermer automatiquement si on repasse en mode desktop
window.addEventListener('resize', () => {
  if (window.innerWidth > 900) {
    sidebar.classList.remove('ouverte');
    overlay.classList.remove('visible');
  }
});