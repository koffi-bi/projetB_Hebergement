// ============================================================
// face_id.js — Interface Face ID avec Google Cloud Vision
// ============================================================

const FACEID_URL = 'https://faceid-service-827v.onrender.com'; // À adapter à votre URL Render
let stream = null;
let videoElement = null;

// ── ENREGISTREMENT (après création du compte) ─────────────
async function activerFaceID(adminId) {
    const btn = document.getElementById('btn-faceid');
    const statut = document.getElementById('faceid-statut');
    const scanner = document.getElementById('faceid-scanner');
    const icone = document.getElementById('faceid-icone');

    if (!adminId) {
        afficherStatut(statut, 'echec', 'Erreur : identifiant administrateur manquant.');
        return;
    }

    btn.disabled = true;
    afficherStatut(statut, 'attente', 'Préparation de la caméra…');
    setIcone(icone, 'scan');

    try {
        const photoBase64 = await capturerPhoto();
        if (!photoBase64) throw new Error('Aucune photo capturée');

        const reponse = await fetch(`${FACEID_URL}/enregistrer-visage`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                photo_base64: photoBase64,
                admin_id: adminId
            })
        });

        if (!reponse.ok) {
            const err = await reponse.json();
            throw new Error(err.detail || `HTTP ${reponse.status}`);
        }

        const data = await reponse.json();
        if (data.success) {
            afficherStatut(statut, 'succes', '✅ Visage enregistré avec succès !');
            setIcone(icone, 'check');
            btn.textContent = '✓ Face ID activé';
            btn.disabled = true;
            setTimeout(() => { window.location.href = 'dashboard.php'; }, 1500);
        } else {
            afficherStatut(statut, 'echec', data.message || 'Échec de l\'enregistrement.');
            setIcone(icone, 'error');
            btn.disabled = false;
        }
    } catch (err) {
        afficherStatut(statut, 'echec', `Erreur : ${err.message}`);
        setIcone(icone, 'error');
        btn.disabled = false;
        console.error('[Face ID]', err);
    } finally {
        arreterCamera();
    }
}

// ── CONNEXION (identification) ─────────────────────────────
async function lancerFaceID() {
    const scanner = document.getElementById('faceid-scanner');
    const icone = document.getElementById('faceid-icone');
    const statut = document.getElementById('faceid-statut');
    const btn = document.getElementById('btn-scanner');

    btn.disabled = true;
    if (scanner) scanner.classList.add('scan-actif');
    setIcone(icone, 'scan');
    afficherStatut(statut, 'attente', 'Prise de photo…');

    try {
        const photoBase64 = await capturerPhoto();
        if (!photoBase64) throw new Error('Impossible de capturer la photo');

        const reponse = await fetch(`${FACEID_URL}/verifier-visage`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ photo_base64: photoBase64 })
        });

        if (!reponse.ok) throw new Error(`HTTP ${reponse.status}`);

        const data = await reponse.json();

        if (data.success && data.token) {
            if (scanner) scanner.classList.add('scan-succes');
            setIcone(icone, 'check');
            afficherStatut(statut, 'succes', `Bienvenue ${data.admin_nom || ''} ! Vérification…`);

            // Envoi du token à PHP
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
                    setTimeout(() => {
                        const canvas = document.createElement('canvas');
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        const photoBase64 = canvas.toDataURL('image/jpeg', 0.8);
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

// ── UTILITAIRES ───────────────────────────────────────────
function afficherStatut(el, type, message) {
    if (!el) return;
    el.className = `faceid-statut ${type}`;
    el.textContent = message;
}

function setIcone(el, etat) {
    if (!el) return;
    const icones = {
        scan: `<svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="1.5">
                <path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/>
                <path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>
                <circle cx="12" cy="12" r="4"/>
                <line x1="12" y1="8" x2="12" y2="4" stroke="#1D6FEB" opacity=".4"/>
                <line x1="16" y1="12" x2="20" y2="12" stroke="#1D6FEB" opacity=".4"/>
                <line x1="12" y1="16" x2="12" y2="20" stroke="#1D6FEB" opacity=".4"/>
                <line x1="8"  y1="12" x2="4"  y2="12" stroke="#1D6FEB" opacity=".4"/>
               </svg>`,
        check: `<svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#38A169" stroke-width="1.8">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
               </svg>`,
        error: `<svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#E53E3E" stroke-width="1.8">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/>
                <line x1="9"  y1="9" x2="15" y2="15"/>
               </svg>`,
        idle: `<svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#1D6FEB" stroke-width="1.5">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                <circle cx="12" cy="7" r="4"/>
               </svg>`
    };
    el.innerHTML = icones[etat] || icones.idle;
}

function afficherEchec(scanner, icone, statut, btn, message) {
    if (scanner) scanner.classList.add('scan-echec');
    setIcone(icone, 'error');
    afficherStatut(statut, 'echec', message);
    if (btn) btn.disabled = false;
}
