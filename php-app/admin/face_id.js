
const FACEID_URL = 'http://localhost:5000';

//  INSCRIPTION — Capture et encodage du visage

async function activerFaceID() {

  const btn    = document.getElementById('btn-faceid');
  const statut = document.getElementById('faceid-statut');
  const input  = document.getElementById('face_encoding_input');

  // Désactiver le bouton pendant la capture
  btn.disabled = true;
  afficherStatut(statut, 'attente', 'Connexion au service de reconnaissance faciale…');

  try {
    // Appel au microservice Python 

    const reponse = await fetch(`${FACEID_URL}/enregistrer-visage`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'capturer' }),
    });

    if (!reponse.ok) {
      throw new Error(`HTTP ${reponse.status} — service Python inaccessible`);
    }

    const data = await reponse.json();

    if (data.success && data.encoding) {

      //Stocker l'encoding dans le champ caché 
      // PHP récupérera cette valeur et la sauvera dans MySQL
      // (colonne face_encoding de la table administrateurs)
      input.value = JSON.stringify(data.encoding);

      // Afficher l'avis de qualité de Groq si disponible
      const avis = data.avis_groq ? ` — ${data.avis_groq}` : '';
      afficherStatut(statut, 'succes', `Face ID activé avec succès !${avis}`);

      // Changer le texte du bouton
      btn.textContent = '✓ Face ID configuré';
      btn.style.background = '#276749'; // vert foncé
      btn.style.color      = '#ffffff';

    } else {
      // Python a répondu mais n'a pas trouvé de visage
      afficherStatut(statut, 'echec', data.message || 'Aucun visage détecté. Réessayez.');
      btn.disabled = false;
    }

  } catch (err) {
    // Le service Python n'est pas joignable
    afficherStatut(statut, 'echec',
      'Service Face ID inaccessible. Vérifiez que le serveur Python tourne sur le port 5000. Vous pouvez continuer sans Face ID.'
    );
    btn.disabled = false;
    console.error('[Face ID] Erreur connexion Python :', err);
  }
}



//  CONNEXION — Identification et génération de token

async function lancerFaceID() {

  const scanner = document.getElementById('faceid-scanner');
  const icone   = document.getElementById('faceid-icone');
  const statut  = document.getElementById('faceid-statut');
  const btn     = document.getElementById('btn-scanner');

  // Démarrer l'animation de scan
  btn.disabled = true;
  scanner.className = 'faceid-scanner scan-actif';
  setIcone(icone, 'scan');
  afficherStatut(statut, 'attente', 'Analyse biométrique en cours…');

  try {
    // Étape 1 : Python identifie le visage 
   
    const reponsePython = await fetch(`${FACEID_URL}/verifier-visage`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'identifier' }),
    });

    if (!reponsePython.ok) {
      throw new Error(`HTTP ${reponsePython.status}`);
    }

    const dataPython = await reponsePython.json();

    if (dataPython.success && dataPython.token) {

      // ── Étape 2 : PHP valide le token en base ────────────────
      
      scanner.className = 'faceid-scanner scan-succes';
      setIcone(icone, 'check');
      afficherStatut(statut, 'succes',
        `Bienvenue ${dataPython.admin_nom || ''} ! Vérification en cours…`
      );

      const reponsePHP = await fetch('login.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    'face_token=' + encodeURIComponent(dataPython.token),
      });

      const dataPHP = await reponsePHP.json();

      if (dataPHP.success) {
        // Session PHP créée → redirection vers le dashboard
        afficherStatut(statut, 'succes', 'Connexion réussie ! Redirection…');
        setTimeout(() => { window.location.href = dataPHP.redirect; }, 800);
      } else {
        // Token invalide ou expiré côté PHP
        afficherEchec(scanner, icone, statut, btn, dataPHP.message || 'Token invalide.');
      }

    } else {
      // Visage non reconnu par Python
      afficherEchec(scanner, icone, statut, btn,
        dataPython.message || 'Visage non reconnu. Essayez un meilleur éclairage ou utilisez votre mot de passe.'
      );
    }

  } catch (err) {
    afficherEchec(scanner, icone, statut, btn,
      'Service Face ID inaccessible. Utilisez votre mot de passe.'
    );
    console.error('[Face ID] Erreur :', err);
  }
}

//  UTILITAIRES PARTAGÉS


/**
 * Affiche un message de statut coloré.
 * @param {HTMLElement} el      - L'élément #faceid-statut
 * @param {string}      type    - 'attente' | 'succes' | 'echec'
 * @param {string}      message - Texte à afficher
 */
function afficherStatut(el, type, message) {
  el.className  = `faceid-statut ${type}`;
  el.textContent = message;
}

/**
 * Change l'icône SVG du scanner selon l'état.
 * @param {HTMLElement} el    - Conteneur de l'icône
 * @param {string}      etat  - 'scan' | 'check' | 'error' | 'idle'
 */
function setIcone(el, etat) {
  const icones = {
    // Icône scan (radar)
    scan: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none"
             stroke="#1D6FEB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
             <path d="M3 7V5a2 2 0 0 1 2-2h2"/>
             <path d="M17 3h2a2 2 0 0 1 2 2v2"/>
             <path d="M21 17v2a2 2 0 0 1-2 2h-2"/>
             <path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
             <circle cx="12" cy="12" r="4" stroke="#1D6FEB"/>
             <line x1="12" y1="8" x2="12" y2="4" stroke="#1D6FEB" opacity=".4"/>
             <line x1="16" y1="12" x2="20" y2="12" stroke="#1D6FEB" opacity=".4"/>
             <line x1="12" y1="16" x2="12" y2="20" stroke="#1D6FEB" opacity=".4"/>
             <line x1="8"  y1="12" x2="4"  y2="12" stroke="#1D6FEB" opacity=".4"/>
           </svg>`,
    // Icône succès (check)
    check: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none"
              stroke="#38A169" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
              <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>`,
    // Icône erreur (x)
    error: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none"
              stroke="#E53E3E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10"/>
              <line x1="15" y1="9" x2="9" y2="15"/>
              <line x1="9"  y1="9" x2="15" y2="15"/>
            </svg>`,
    // Icône par défaut (visage)
    idle: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none"
             stroke="#1D6FEB" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
             <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
             <circle cx="12" cy="7" r="4"/>
           </svg>`,
  };
  el.innerHTML = icones[etat] || icones.idle;
}

/**
 * Gère l'affichage d'un échec du Face ID.
 */
function afficherEchec(scanner, icone, statut, btn, message) {
  scanner.className = 'faceid-scanner scan-echec';
  setIcone(icone, 'error');
  afficherStatut(statut, 'echec', message);
  btn.disabled = false;
}
