<?php
// ================================================================
//  CvmathchIA — Traitement upload CV + Photo + Analyse IA
//  Fichier : candidat/upload_cv.php
//
//  FLUX COMPLET

require_once '../config/config.php';
require_once '../config/auth_functions.php';
demarrerSession();

// ── Sécurité : candidat connecté uniquement ──────────────────
if (empty($_SESSION['candidat_id'])) {
    rediriger('login.php');
}

// ── Vérifier que c'est bien un POST avec l'action correcte ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'uploader_cv') {
    rediriger('espace.php#steps');
}

$candidat_id = $_SESSION['candidat_id'];
$pdo         = connexionDB();

// ── Charger le candidat (pour supprimer l'ancienne photo) ─────
$stmt = $pdo->prepare("SELECT * FROM candidats WHERE id = ?");
$stmt->execute([$candidat_id]);
$candidat = $stmt->fetch();

$erreurs = [];
$succes  = [];

// ================================================================
//  ÉTAPE 1 — UPLOAD DU CV (obligatoire)
// ================================================================

$types_cv_ok   = ['pdf', 'docx', 'doc', 'jpg', 'jpeg', 'png'];
$taille_max_ko = 5120;  // 5 Mo
$chemin_cv_relatif = null;

if (!isset($_FILES['cv_fichier']) || $_FILES['cv_fichier']['error'] !== UPLOAD_ERR_OK) {
    $erreurs[] = 'CV : aucun fichier reçu ou erreur lors de l\'upload.';
} else {
    $fichier   = $_FILES['cv_fichier'];
    $nom_orig  = basename($fichier['name']);
    $extension = strtolower(pathinfo($nom_orig, PATHINFO_EXTENSION));
    $taille_ko = intval($fichier['size'] / 1024);

    if (!in_array($extension, $types_cv_ok)) {
        $erreurs[] = 'CV : format non autorisé. Utilisez PDF, DOCX, DOC, JPG ou PNG.';
    } elseif ($taille_ko > $taille_max_ko) {
        $erreurs[] = 'CV : fichier trop lourd (max. 5 Mo).';
    } else {
        // Créer le dossier si nécessaire
        if (!is_dir(UPLOAD_CV_DIR)) {
            mkdir(UPLOAD_CV_DIR, 0755, true);
        }

        // Nom unique pour éviter les collisions
        $nom_unique = 'cv_' . $candidat_id . '_' . time() . '.' . $extension;
        $chemin_abs = UPLOAD_CV_DIR . $nom_unique;
        $chemin_cv_relatif = 'uploads/cv/' . $nom_unique;

        if (move_uploaded_file($fichier['tmp_name'], $chemin_abs)) {

            // ── Insérer le CV en base (statut 'en_attente') ──────
            // Le statut passera à 'analyse' après l'appel Python ci-dessous
            $pdo->prepare("
                INSERT INTO cv (candidat_id, nom_fichier, chemin, type_fichier, taille_ko, statut_analyse)
                VALUES (?, ?, ?, ?, ?, 'en_attente')
            ")->execute([$candidat_id, $nom_orig, $chemin_cv_relatif, $extension, $taille_ko]);

            $succes[] = "CV « $nom_orig » enregistré.";

        } else {
            $erreurs[] = 'CV : impossible d\'enregistrer le fichier (vérifiez les permissions).';
            $chemin_cv_relatif = null;
        }
    }
}


// ================================================================
//  ÉTAPE 2 — UPLOAD DE LA PHOTO (facultatif)
// ================================================================

$photo_envoyee = isset($_FILES['photo_profil'])
              && $_FILES['photo_profil']['error'] !== UPLOAD_ERR_NO_FILE;

if ($photo_envoyee) {
    $types_photo_ok  = ['jpg', 'jpeg', 'png', 'webp'];
    $taille_max_photo = 2048;  // 2 Mo

    $fp        = $_FILES['photo_profil'];
    $ext_photo = strtolower(pathinfo($fp['name'], PATHINFO_EXTENSION));
    $taille_ph = intval($fp['size'] / 1024);

    if ($fp['error'] !== UPLOAD_ERR_OK) {
        $erreurs[] = 'Photo : erreur lors de l\'upload.';
    } elseif (!in_array($ext_photo, $types_photo_ok)) {
        $erreurs[] = 'Photo : format non autorisé (JPG, PNG, WEBP).';
    } elseif ($taille_ph > $taille_max_photo) {
        $erreurs[] = 'Photo : trop lourde (max. 2 Mo).';
    } elseif (!@getimagesize($fp['tmp_name'])) {
        $erreurs[] = 'Photo : le fichier n\'est pas une image valide.';
    } else {
        if (!is_dir(UPLOAD_PHOTO_DIR)) {
            mkdir(UPLOAD_PHOTO_DIR, 0755, true);
        }

        // Supprimer l'ancienne photo si elle existe
        if (!empty($candidat['photo'])) {
            $ancienne = UPLOAD_PHOTO_DIR . basename($candidat['photo']);
            if (file_exists($ancienne)) {
                @unlink($ancienne);
            }
        }

        $nom_photo  = 'photo_' . $candidat_id . '_' . time() . '.' . $ext_photo;
        $chemin_photo = UPLOAD_PHOTO_DIR . $nom_photo;

        if (move_uploaded_file($fp['tmp_name'], $chemin_photo)) {
            $pdo->prepare("UPDATE candidats SET photo = ? WHERE id = ?")
                ->execute(['uploads/photos/' . $nom_photo, $candidat_id]);
            $succes[] = 'Photo de profil enregistrée.';
        } else {
            $erreurs[] = 'Photo : impossible d\'enregistrer (vérifiez les permissions).';
        }
    }
}


// ================================================================
//  ÉTAPE 3 — APPEL PYTHON POUR ANALYSE IA DU CV
//
//  On appelle le microservice Python /analyser-cv-fichier.
//  Python lit le fichier PDF/DOCX, Groq extrait :
//    - Le poste principal
//    - Les compétences
//    - Les villes mentionnées
//    - Les formations
//    - Un résumé narratif complet
//
//  Tout est stocké dans cv.resume_ia et enrichit le profil candidat.
//  Cette colonne est ensuite utilisée pour les recherches de l'admin.
// ================================================================

$analyse_ia_reussie = false;
$analyse_ia_message = '';

if ($chemin_cv_relatif !== null) {
    // URL du microservice Python (doit tourner sur le port 5001)
    $matching_api_url = getenv('MATCHING_API_URL') ?: 'http://localhost:5001';
    $url_python = rtrim($matching_api_url, '/') . '/analyser-cv-fichier';
    $payload = json_encode([
        'candidat_id' => (int) $candidat_id,
        'chemin_cv'   => $chemin_cv_relatif,
    ]);

    // Appel HTTP POST avec curl (non bloquant si Python est lent)
    $ch = curl_init($url_python);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,  // 2 min max (Groq peut être lent)
        CURLOPT_CONNECTTIMEOUT => 5,    // 5s pour la connexion initiale
    ]);

    $reponse_json = curl_exec($ch);
    $code_http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur_curl  = curl_error($ch);
    curl_close($ch);

    if ($erreur_curl) {
        // Python n'est pas joignable — le CV reste en statut 'en_attente'
        // Il sera analysé au prochain démarrage du service Python
        $analyse_ia_message = "CV enregistré. L'analyse IA se fera automatiquement (service Python non joignable).";
    } elseif ($code_http === 200) {
        $data = json_decode($reponse_json, true);
        if (!empty($data['success'])) {
            $analyse_ia_reussie = true;
            $analyse_ia_message = $data['message'] ?? 'CV analysé par l\'IA avec succès.';
            $succes[] = '🤖 ' . $analyse_ia_message;
        } else {
            $analyse_ia_message = $data['message'] ?? 'Analyse IA partielle.';
            $succes[] = 'CV enregistré. ' . $analyse_ia_message;
        }
    } elseif ($code_http === 429) {
        // Limite de tokens Groq atteinte — sera analysé plus tard
        $succes[] = 'CV enregistré. Analyse IA différée (limite Groq atteinte).';
    } else {
        $succes[] = 'CV enregistré. Analyse IA en attente.';
    }
}


// ================================================================
//  REDIRECTION FINALE avec message en session flash
// ================================================================

if (!empty($erreurs)) {
    $_SESSION['flash_espace'] = [
        'type'  => 'erreur',
        'texte' => implode('<br>', $erreurs),
    ];
} else {
    $_SESSION['flash_espace'] = [
        'type'  => 'succes',
        'texte' => implode(' · ', $succes),
    ];
}

// Rediriger vers l'espace candidat, onglet CV (Step 3 visible)
rediriger('espace.php?step=3#steps');
