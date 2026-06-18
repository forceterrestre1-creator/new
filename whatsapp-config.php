<?php
/**
 * 718Digital - Webhook WhatsApp Business (Agent Accueil)
 *
 * Reçoit les messages WhatsApp entrants, les traite avec l'Agent Accueil (Mistral),
 * et répond automatiquement au client. C'est le premier agent vraiment autonome.
 *
 * CONFIGURATION CÔTÉ META DEVELOPER :
 * 1. App Meta > WhatsApp > Configuration
 * 2. URL de rappel (Callback URL) : https://tondomaine.ci/api/webhook.php
 * 3. Verify Token : doit être identique à WHATSAPP_VERIFY_TOKEN dans .env
 * 4. Champs d'abonnement (Webhook fields) : cocher "messages"
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp-config.php';

// --- 1. VÉRIFICATION DU WEBHOOK (Meta envoie un GET une seule fois, à la config) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && $token === WHATSAPP_VERIFY_TOKEN) {
        http_response_code(200);
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Verification failed';
    exit;
}

// --- 2. RÉCEPTION DES MESSAGES (Meta envoie un POST à chaque message client) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');

    // Vérification de signature Meta (sécurité - recommandé en production)
    if (!empty(WHATSAPP_APP_SECRET)) {
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, WHATSAPP_APP_SECRET);
        if (!hash_equals($expected, $signature)) {
            http_response_code(403);
            exit;
        }
    }

    $data = json_decode($rawBody, true);

    // Répond immédiatement 200 à Meta (évite les retentatives/timeout)
    http_response_code(200);
    echo 'EVENT_RECEIVED';
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    processIncomingMessage($data);
    exit;
}

http_response_code(405);
exit;

// ============================================================
// LOGIQUE DE L'AGENT ACCUEIL
// ============================================================

function processIncomingMessage($data) {
    $entry = $data['entry'][0] ?? null;
    if (!$entry) return;
    $changes = $entry['changes'][0] ?? null;
    if (!$changes) return;
    $value = $changes['value'] ?? null;
    $messages = $value['messages'] ?? null;
    if (!$messages) return; // accusés de lecture, pas un vrai message

    $message = $messages[0];
    $from = $message['from'];
    $msgType = $message['type'];

    if ($msgType !== 'text') {
        sendWhatsAppMessage($from, "Merci pour votre message ! Pour l'instant, je traite uniquement le texte. Pouvez-vous m'écrire votre besoin ?");
        return;
    }

    $userText = trim($message['text']['body'] ?? '');
    if (empty($userText)) return;

    $history = loadConversation($from);
    $history[] = ['role' => 'user', 'content' => $userText];

    $reply = callAgentAccueil($history);

    $history[] = ['role' => 'assistant', 'content' => $reply];
    saveConversation($from, $history);

    sendWhatsAppMessage($from, $reply);

    $logLine = date('Y-m-d H:i:s') . " | {$from} | IN: " . substr($userText, 0, 100) . " | OUT: " . substr($reply, 0, 100) . "\n";
    @file_put_contents(__DIR__ . '/storage/whatsapp_log.txt', $logLine, FILE_APPEND);
}

function loadConversation($phone) {
    $dir = __DIR__ . '/storage/conversations';
    if (!file_exists($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/' . preg_replace('/[^0-9]/', '', $phone) . '.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return array_slice($data ?: [], -16); // limite le contexte / coût
}

function saveConversation($phone, $history) {
    $dir = __DIR__ . '/storage/conversations';
    if (!file_exists($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/' . preg_replace('/[^0-9]/', '', $phone) . '.json';
    file_put_contents($file, json_encode(array_slice($history, -16)));
}

function callAgentAccueil($history) {
    $systemPrompt = "Tu es l'Agent Accueil de 718Digital, une agence de communication digitale à Abidjan, Côte d'Ivoire. Tu réponds aux clients sur WhatsApp 24h/24. Tu es chaleureux, professionnel et efficace. Ton objectif : comprendre le besoin du client (nom, type de projet, budget estimé en FCFA, délai souhaité), puis le qualifier comme CHAUD, TIÈDE ou FROID. Tu réponds en français (ou dioula si le client écrit en dioula). Tu poses UNE seule question à la fois. Quand le besoin est clair, dis au client qu'un membre de l'équipe va lui envoyer un devis personnalisé sous peu. Reste très concis : 3-4 lignes maximum par message, c'est WhatsApp pas un email.";

    $messages = array_merge(
        [['role' => 'system', 'content' => $systemPrompt]],
        $history
    );

    $payload = [
        'model' => MISTRAL_MODEL,
        'max_tokens' => 300,
        'temperature' => 0.6,
        'messages' => $messages,
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
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['choices'][0]['message']['content']
        ?? "Merci pour votre message ! Un membre de notre équipe 718Digital vous répond très vite.";
}

function sendWhatsAppMessage($to, $text) {
    $url = 'https://graph.facebook.com/v20.0/' . WHATSAPP_PHONE_NUMBER_ID . '/messages';

    $payload = [
        'messaging_product' => 'whatsapp',
        'to' => $to,
        'type' => 'text',
        'text' => ['body' => $text],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WHATSAPP_TOKEN,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}
