<?php
/**
 * 718Digital - Configuration WhatsApp Business API (Meta)
 * Charge les identifiants depuis .env (config.php doit être inclus avant ce fichier)
 */

define('WHATSAPP_TOKEN', $_ENV['WHATSAPP_TOKEN'] ?? '');
define('WHATSAPP_PHONE_NUMBER_ID', $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '');
define('WHATSAPP_VERIFY_TOKEN', $_ENV['WHATSAPP_VERIFY_TOKEN'] ?? '718digital_verify_2026');
define('WHATSAPP_APP_SECRET', $_ENV['WHATSAPP_APP_SECRET'] ?? '');

if (empty(WHATSAPP_TOKEN) || empty(WHATSAPP_PHONE_NUMBER_ID)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'WHATSAPP_TOKEN ou WHATSAPP_PHONE_NUMBER_ID manquant dans .env']);
    exit;
}
