// ============================================================
// face_id.js — Interface Face ID avec capture photo côté client
// ============================================================

const FACEID_URL = 'https://faceid-service.onrender.com'; // À adapter selon votre URL Render

let stream = null;        // flux vidéo
let videoElement = null;  // élément vidéo (créé dynamiquement)

// ── ENREGISTREMENT (admin déjà créé) ───────────────────────
async function activerFaceID(adminId) {
  const btn = document.getElementById('btn-faceid');
  const statut = document.getElementById('faceid-statut');

  if (!adminId) {
    afficherStatut(statut, 'echec', 'Erreur : identifiant administrateur manquant.');
    return;
  }

  btn.disabled = true;
  afficherStatut(statut, 'attente', 'Préparation de la caméra…');

  try {
    // 1. Capturer une photo
    const photoBase64 = await capturerPhoto();
    if (!photoBase64) {
      throw new Error('Aucune photo capturée');
    }

    // 2. Envoyer au microservice Python
    const reponse = await fetch(`${FACEID_URL}/enregistrer-visage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        photo_base64: photoBase64,
        admin_id: adminId
      })
    });

    if (!reponse.ok) {
      const erreur = await reponse.json();
      throw new Error(erreur.detail || `HTTP ${reponse.status}`);
    }

    const data = await reponse.json();
    if (data.success) {
      afficherStatut(statut, 'succes', 'Visage enregistré avec succès !');
      btn.textContent = '✓ Face ID configuré';
      btn.style.background = '#276749';
      btn.style.color = '#ffffff';
    } else {
      afficherStatut(statut, 'echec', data.message || 'Échec de l\'enregistrement.');
      btn.disabled = false;
    }
  } catch (err) {
    afficherStatut(statut, 'echec', `Erreur : ${err.message}`);
    btn.disabled = false;
    console.error('[Face ID]', err);
  } finally {
    arreterCamera();
  }
}

// ── CONNEXION (identification) ──────────────────────────────
async function lancerFaceID() {
  const scanner = document.getElementById('faceid-scanner');
  const icone = document.getElementById('faceid-icone');
  const statut = document.getElementById('faceid-statut');
  const btn = document.getElementById('btn-scanner');

  btn.disabled = true;
  scanner.className = 'faceid-scanner scan-actif';
  setIcone(icone, 'scan');
  afficherStatut(statut, 'attente', 'Prise de photo…');

  try {
    // 1. Capturer une photo
    const photoBase64 = await capturerPhoto();
    if (!photoBase64) {
      throw new Error('Impossible de capturer la photo');
    }

    // 2. Vérifier auprès du microservice
    const reponse = await fetch(`${FACEID_URL}/verifier-visage`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ photo_base64: photoBase64 })
    });

    if (!reponse.ok) {
      throw new Error(`HTTP ${reponse.status}`);
    }

    const data = await reponse.json();

    if (data.success && data.token) {
      // 3. Token valide → l'envoyer à PHP pour créer la session
      scanner.className = 'faceid-scanner scan-succes';
      setIcone(icone, 'check');
      afficherStatut(statut, 'succes', `Bienvenue ${data.admin_nom || ''} ! Vérification…`);

      const reponsePHP = await fetch('login.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'face_token=' + encodeURIComponent(data.token)
      });

      const dataPHP = await reponsePHP.json();
      if (dataPHP.success) {
        afficherStatut(statut, 'succes', 'Connexion réussie ! Redirection…');
        setTimeout(() => { window.location.href = dataPHP.redirect; }, 800);
      } else {
        afficherEchec(scanner, icone, statut, btn, dataPHP.message || 'Token invalide.');
      }
    } else {
      afficherEchec(scanner, icone, statut, btn, data.message || 'Visage non reconnu.');
    }
  } catch (err) {
    afficherEchec(scanner, icone, statut, btn, `Service indisponible : ${err.message}`);
    console.error('[Face ID]', err);
  } finally {
    arreterCamera();
  }
}

// ── CAPTURE PHOTO (via webcam) ─────────────────────────────
function capturerPhoto() {
  return new Promise((resolve, reject) => {
    // Créer un élément vidéo temporaire
    const video = document.createElement('video');
    video.setAttribute('autoplay', '');
    video.setAttribute('playsinline', '');
    video.style.display = 'none';
    document.body.appendChild(video);
    videoElement = video;

    navigator.mediaDevices.getUserMedia({ video: true })
      .then(streamMedia => {
        stream = streamMedia;
        video.srcObject = stream;
        video.onloadedmetadata = () => {
          video.play();
          // Laisser le temps à la caméra de s'adapter
          setTimeout(() => {
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const photoBase64 = canvas.toDataURL('image/jpeg', 0.8);
            // Nettoyer
            arreterCamera();
            document.body.removeChild(video);
            resolve(photoBase64);
          }, 300);
        };
      })
      .catch(err => {
        arreterCamera();
        reject(new Error('Impossible d’accéder à la caméra : ' + err.message));
      });
  });
}

function arreterCamera() {
  if (stream) {
    stream.getTracks().forEach(track => track.stop());
    stream = null;
  }
  if (videoElement && videoElement.parentNode) {
    videoElement.parentNode.removeChild(videoElement);
    videoElement = null;
  }
}

// ── UTILITAIRES (inchangés) ────────────────────────────────
function afficherStatut(el, type, message) {
  el.className = `faceid-statut ${type}`;
  el.textContent = message;
}

function setIcone(el, etat) {
  const icones = {
    scan: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="1.5">...</svg>`,
    check: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#38A169" stroke-width="1.8">...</svg>`,
    error: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#E53E3E" stroke-width="1.8">...</svg>`,
    idle: `<svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="1.5">...</svg>`
  };
  el.innerHTML = icones[etat] || icones.idle;
}

function afficherEchec(scanner, icone, statut, btn, message) {
  scanner.className = 'faceid-scanner scan-echec';
  setIcone(icone, 'error');
  afficherStatut(statut, 'echec', message);
  btn.disabled = false;
}
