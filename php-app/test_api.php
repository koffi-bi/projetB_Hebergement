<?php
// test_api.php - À SUPPRIMER APRÈS LE TEST
echo "<h1>Test de connexion à l'API Matching</h1>";

// 1. Vérifier que la variable est bien lue
$api_url = getenv('MATCHING_API_URL');
echo "<p><strong>URL de l'API lue :</strong> " . ($api_url ? $api_url : 'VARIABLE NON TROUVEE') . "</p>";

// 2. Vérifier la réponse de l'API
if ($api_url) {
    $test_endpoint = rtrim($api_url, '/') . '/sante';
    echo "<p><strong>Test de l'endpoint :</strong> <code>$test_endpoint</code></p>";
    
    $ch = curl_init($test_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($response) {
        echo "<p><strong>✅ Réponse de l'API :</strong> <pre>" . htmlspecialchars($response) . "</pre></p>";
    } else {
        echo "<p><strong>❌ Erreur de connexion :</strong> $error</p>";
    }
}
?>
