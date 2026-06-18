<?php
/**
 * 718digi SiteBuilder - Proxy sécurisé vers Mistral AI
 * Reçoit les paramètres du site depuis le frontend (JS),
 * appelle Mistral avec la clé API cachée côté serveur,
 * et renvoie le code HTML généré.
 *
 * Endpoint : POST /generate-site.php
 * Body JSON attendu :
 * {
 *   "companyName": "Chez Lorna",
 *   "siteType": "Salon / Beauté",
 *   "style": "Luxe & Élégant",
 *   "lang": "Français",
 *   "colors": "Or et noir",
 *   "description": "..."
 * }
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

// --- CORS : autorise uniquement ton domaine ---
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée. Utilisez POST.']);
    exit;
}

// --- Rate limiting simple basé fichier (anti-abus, anti-facture surprise) ---
function checkRateLimit($ip, $maxRequests = 10, $windowSeconds = 3600) {
    $storageDir = __DIR__ . '/storage';
    if (!file_exists($storageDir)) {
        mkdir($storageDir, 0755, true);
    }
    $rateFile = $storageDir . '/ratelimit_' . md5($ip) . '.json';
    $now = time();
    $data = file_exists($rateFile)
        ? json_decode(file_get_contents($rateFile), true)
        : ['count' => 0, 'start' => $now];

    if ($now - $data['start'] > $windowSeconds) {
        $data = ['count' => 0, 'start' => $now];
    }
    $data['count']++;
    file_put_contents($rateFile, json_encode($data));

    return $data['count'] <= $maxRequests;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit($clientIp)) {
    http_response_code(429);
    echo json_encode(['error' => 'Trop de requêtes depuis cette adresse. Réessayez dans une heure.']);
    exit;
}

// --- Lecture et validation des données entrantes ---
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Données JSON invalides.']);
    exit;
}

function sanitize($str, $maxLen = 3000) {
    $str = trim($str ?? '');
    $str = substr($str, 0, $maxLen);
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

$companyName = sanitize($input['companyName'] ?? '', 100);
$siteType    = sanitize($input['siteType'] ?? '', 50);
$style       = sanitize($input['style'] ?? 'Moderne', 50);
$lang        = sanitize($input['lang'] ?? 'Français', 50);
$colors      = sanitize($input['colors'] ?? '', 200);
$description = sanitize($input['description'] ?? '', 3000);

if (empty($companyName) || empty($siteType) || empty($description)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nom, type de site et description sont requis.']);
    exit;
}

// --- Construction du prompt envoyé à Mistral ---
$prompt = "Tu es un expert en développement web et design UI/UX. Crée un site web complet, professionnel et moderne en HTML/CSS/JS dans un seul fichier.

INFORMATIONS DU SITE :
- Nom : {$companyName}
- Type : {$siteType}
- Style visuel : {$style}
- Langue(s) : {$lang}
- Couleurs souhaitées : {$colors}
- Description détaillée : {$description}

EXIGENCES TECHNIQUES STRICTES :
1. Tout en UN SEUL fichier HTML complet (<!DOCTYPE html> ... </html>)
2. CSS embarqué dans <style> - design premium, responsive mobile-first
3. JavaScript embarqué dans <script> - interactions fluides
4. Navigation fixe avec menu hamburger mobile
5. Hero section impactante avec appel à l'action
6. Sections adaptées au type de site demandé
7. Section contact avec formulaire fonctionnel
8. Footer complet avec réseaux sociaux
9. Animations CSS subtiles (fade-in, hover effects)
10. Google Fonts + Font Awesome via CDN
11. Optimisé pour la conversion (CTA clairs)
12. Pas de framework externe lourd - CSS et JS vanilla uniquement

IMPORTANT : Retourne UNIQUEMENT le code HTML complet, sans explication, sans markdown, sans backticks. Commence directement par <!DOCTYPE html>";

// --- Appel à l'API Mistral via cURL ---
$payload = [
    'model' => MISTRAL_MODEL,
    'max_tokens' => 8000,
    'temperature' => 0.7,
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ]
];

$ch = curl_init('https://api.mistral.ai/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . MISTRAL_API_KEY,
    ],
    CURLOPT_TIMEOUT => 90,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur de connexion à Mistral : ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    http_response_code($httpCode ?: 500);
    echo json_encode(['error' => 'Erreur API Mistral (code ' . $httpCode . ')']);
    exit;
}

$data = json_decode($response, true);
$htmlCode = $data['choices'][0]['message']['content'] ?? '';

// --- Nettoyage des balises markdown éventuelles ---
$htmlCode = preg_replace('/^```html\s*/i', '', trim($htmlCode));
$htmlCode = preg_replace('/^```\s*/i', '', $htmlCode);
$htmlCode = preg_replace('/```\s*$/i', '', $htmlCode);
$htmlCode = trim($htmlCode);

if (empty($htmlCode)) {
    http_response_code(500);
    echo json_encode(['error' => "Mistral n'a renvoyé aucun contenu."]);
    exit;
}

// --- Log simple de suivi (facultatif) ---
$logEntry = date('Y-m-d H:i:s') . " | {$clientIp} | {$companyName} | {$siteType}\n";
@file_put_contents(__DIR__ . '/storage/generation_log.txt', $logEntry, FILE_APPEND);

// --- Réponse finale ---
echo json_encode([
    'success' => true,
    'html' => $htmlCode,
    'companyName' => $companyName,
]);
