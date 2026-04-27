// upload_cv.php — Version corrigée
// ...

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['cv_fichier'])) {
    // ... (validation inchangée) ...
    
    try {
        // 1. Upload vers Cloudinary (inchangé)
        $result = $cloudinary->uploadApi()->upload($fichier['tmp_name'], [
            'folder'        => 'candidats/' . $candidat_id . '/cv',
            'public_id'     => 'cv_' . time(),
            'resource_type' => 'auto',
            'type'          => 'upload',
        ]);
        
        $url_cloudinary = $result['secure_url'];
        
        // 2. Sauvegarde en BDD (inchangé)
        $pdo  = connexionDB();
        $stmt = $pdo->prepare("
            INSERT INTO cv (candidat_id, nom_fichier, chemin, type_fichier, taille_ko, statut_analyse)
            VALUES (?, ?, ?, ?, ?, 'en_attente')
        ");
        $stmt->execute([$candidat_id, $fichier['name'], $url_cloudinary, $ext, $taille]);
        
        // 3. Appel au microservice Python — VERSION CORRIGÉE
        $matching_api_url = rtrim(getenv('MATCHING_API_URL') ?: 'http://localhost:5001', '/');
        
        // Utiliser /cv-uploade (qui accepte cloudinary_url explicitement)
        $payload = json_encode([
            'candidat_id'    => $candidat_id,
            'cloudinary_url' => $url_cloudinary,   // champ spécifique
            'chemin_cv'      => '',                 // laisser vide
            'texte_cv'       => '',
        ]);
        
        $ch = curl_init($matching_api_url . '/cv-uploade');  // ← CHANGEMENT ICI
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        
        $response     = curl_exec($ch);
        $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error   = curl_error($ch);
        curl_close($ch);
        
        // Log détaillé
        error_log("[SmartRecruit] Réponse microservice: HTTP $http_code - " . substr($response, 0, 200));
        
        if ($curl_error) {
            error_log("[SmartRecruit] cURL erreur: $curl_error");
            $erreur = "Erreur technique lors de l'analyse. Le CV sera analysé automatiquement.";
        } elseif ($http_code === 200) {
            $decoded = json_decode($response, true);
            $succes = 'CV envoyé et analysé avec succès !';
        } else {
            error_log("[SmartRecruit] HTTP $http_code: $response");
            $succes = 'CV uploadé. L\'analyse IA est en file d\'attente.';
        }
        
    } catch (\Exception $e) {
        $erreur = 'Erreur : ' . $e->getMessage();
        error_log("[SmartRecruit] Exception: " . $e->getMessage());
    }
}
