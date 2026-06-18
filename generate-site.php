<?php
/**
 * 718Digital - Configuration sécurisée
 * Charge la clé API Mistral depuis le fichier .env
 * NE JAMAIS écrire la clé directement dans ce fichier, dans le HTML, ou dans le JS.
 */

function loadEnv($path) {
    if (!file_exists($path)) {
        throw new Exception("Fichier .env introuvable. Copiez .env.example vers .env et renseignez vos clés.");
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

loadEnv(__DIR__ . '/.env');

define('MISTRAL_API_KEY', $_ENV['MISTRAL_API_KEY'] ?? '');
define('ALLOWED_ORIGIN', $_ENV['ALLOWED_ORIGIN'] ?? '*');
define('MISTRAL_MODEL', $_ENV['MISTRAL_MODEL'] ?? 'mistral-large-latest');

if (empty(MISTRAL_API_KEY) || MISTRAL_API_KEY === 'votre_cle_mistral_ici') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'MISTRAL_API_KEY non configurée. Vérifiez le fichier .env sur le serveur.']);
    exit;
}
