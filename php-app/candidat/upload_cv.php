<?php
// upload_cv.php — Stockage Cloudinary + appel microservice Python (Render)
// v2.0 : timeout augmenté + passage de cloudinary_url + appel asynchrone
require_once '../config/config.php';
require_once '../config/auth_functions.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Cloudinary\Cloudinary;

demarrerSession();

if (empty($_SESSION['candidat_id'])) {
    rediriger('login.php');
}

$candidat_id = (int)$_SESSION['candidat_id'];
$erreur = '';
$succes = '';

// ── Initialisation Cloudinary ─────────────────────────────────────────────────
$cloudinary = new Cloudinary([
    'cloud' => [
        'cloud_name' => CLOUDINARY_CLOUD_NAME,
        'api_key'    => CLOUDINARY_API_KEY,
        'api_secret' => CLOUDINARY_API_SECRET,
    ],
    'url' => ['secure' => true]
]);

// ── Traitement du formulaire ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cv_fichier'])) {
    $fichier = $_FILES['cv_fichier'];

    if ($fichier['error'] !== UPLOAD_ERR_OK) {
        $erreur = 'Erreur lors du téléchargement. Code : ' . $fichier['error'];
    } else {
        $extensions_autorisees = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
        $ext    = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
        $taille = (int)round($fichier['size'] / 1024);

        if (!in_array($ext, $extensions_autorisees)) {
            $erreur = 'Format non autorisé. Formats acceptés : PDF, DOCX, JPG, PNG.';
        } elseif ($taille > 5120) {
            $erreur = 'Fichier trop volumineux (maximum 5 Mo).';
        } else {
            try {
                // ── 1. Upload vers Cloudinary ─────────────────────────────────
                $result = $cloudinary->uploadApi()->upload(
                    $fichier['tmp_name'],
                    [
                        'folder'        => 'candidats/' . $candidat_id . '/cv',
                        'public_id'     => 'cv_' . time(),
                        'resource_type' => 'auto',
                        // Forcer la livraison du fichier brut (non transformé)
                        // pour que Python puisse le télécharger correctement
                        'type'          => 'upload',
                    ]
                );

                $url_cloudinary = $result['secure_url'];

                // ── 2. Sauvegarde en base de données ──────────────────────────
                $pdo  = connexionDB();
                $stmt = $pdo->prepare("
                    INSERT INTO cv
                        (candidat_id, nom_fichier, chemin, type_fichier, taille_ko, statut_analyse)
                    VALUES
                        (?, ?, ?, ?, ?, 'en_attente')
                ");
                $stmt->execute([$candidat_id, $fichier['name'], $url_cloudinary, $ext, $taille]);

                // ── 3. Appel au microservice Python (Render) ──────────────────
                // IMPORTANT :
                //   • On passe cloudinary_url (et non chemin local)
                //   • Timeout à 120s — Render peut être lent au démarrage (cold start)
                //     et le téléchargement + extraction + Groq prennent du temps.
                //   • On ignore le résultat (fire-and-forget) pour ne pas bloquer
                //     la réponse HTTP au candidat. Le statut sera mis à jour en BDD
                //     par le microservice une fois l'analyse terminée.
                $matching_api_url = rtrim(
                    getenv('MATCHING_API_URL') ?: 'http://localhost:5001',
                    '/'
                );

                $payload = json_encode([
                    'candidat_id'    => $candidat_id,
                    'cloudinary_url' => $url_cloudinary,   // ← URL Cloudinary
                    'chemin_cv'      => $url_cloudinary,   // ← aussi dans chemin_cv pour compatibilité
                    'texte_cv'       => '',
                ]);

                // Appel cURL vers /analyser-cv-fichier
                // (endpoint qui accepte une URL Cloudinary dans chemin_cv)
                $ch = curl_init($matching_api_url . '/analyser-cv-fichier');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'Content-Type: application/json',
                        'Content-Length: ' . strlen($payload),
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    // 120s : laisse le temps à Render de télécharger depuis Cloudinary
                    // + extraire le texte + appeler Groq
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    // Ne pas vérifier le SSL en dev local (à retirer en prod si possible)
                    // CURLOPT_SSL_VERIFYPEER => false,
                ]);

                $response     = curl_exec($ch);
                $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error   = curl_error($ch);
                curl_close($ch);

                // Log pour débogage (visible dans les logs Render)
                if ($curl_error) {
                    error_log("[SmartRecruit] cURL erreur microservice : $curl_error");
                } elseif ($http_code !== 200) {
                    error_log("[SmartRecruit] Microservice HTTP $http_code : $response");
                } else {
                    $decoded = json_decode($response, true);
                    if (!empty($decoded['success'])) {
                        error_log("[SmartRecruit] CV analysé : " . ($decoded['message'] ?? 'OK'));
                    } else {
                        error_log("[SmartRecruit] Analyse échouée : " . ($decoded['message'] ?? 'inconnu'));
                    }
                }

                // Le succès est affiché même si l'analyse n'est pas encore terminée —
                // le statut 'en_attente' sera mis à 'analyse' ou 'erreur' par Python.
                $succes = 'CV envoyé avec succès ! L\'analyse IA est en cours.';

            } catch (\Cloudinary\Api\Exception\ApiError $e) {
                $erreur = 'Erreur Cloudinary : ' . $e->getMessage();
                error_log("[SmartRecruit] Cloudinary API error : " . $e->getMessage());
            } catch (\Exception $e) {
                $erreur = 'Erreur inattendue : ' . $e->getMessage();
                error_log("[SmartRecruit] Exception upload : " . $e->getMessage());
            }
        }
    }
}

// ── Redirection avec message flash ───────────────────────────────────────────
if ($succes) $_SESSION['flash'] = ['type' => 'succes', 'texte' => $succes];
if ($erreur) $_SESSION['flash'] = ['type' => 'erreur', 'texte' => $erreur];

rediriger('dashboard.php?onglet=cv');
?>
