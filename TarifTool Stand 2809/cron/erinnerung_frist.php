<?php
// ============================================================
// cron/erinnerung_frist.php
// Täglicher Auslöser für die 7-Tage-Frist-Erinnerungsmail ans Büro.
//
// Einrichtung (zwei Varianten, je nachdem was beim Hoster verfügbar ist):
//
// A) Echter Server-Cron (SSH/Crontab-Zugriff vorhanden):
//    0 7 * * *  php /pfad/zum/projekt/cron/erinnerung_frist.php
//
// B) Kein Crontab-Zugriff (z.B. reines Webhosting ohne SSH):
//    Externen Cron-Dienst (z.B. cron-job.org) täglich auf
//    https://IHRE-DOMAIN/cron/erinnerung_frist.php?key=DAS_GEHEIME_TOKEN
//    zeigen lassen. Das Token wird unten aus der Konstante
//    CRON_SECRET geprüft (in _backend/bootstrap.php oder config.php
//    definieren, z.B.:  define('CRON_SECRET', 'ein-langes-zufaelliges-token');)
//    – ohne gültiges Token bricht das Script sofort ab.
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/erinnerung_helpers.php';

$istCli = (php_sapi_name() === 'cli');

if (!$istCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!defined('CRON_SECRET') || CRON_SECRET === '' ) {
        http_response_code(403);
        exit("CRON_SECRET ist nicht konfiguriert – Aufruf über HTTP ist deaktiviert.\n");
    }
    $key = $_GET['key'] ?? '';
    if (!hash_equals(CRON_SECRET, (string)$key)) {
        http_response_code(403);
        exit("Ungültiges Token.\n");
    }
}

$result = erinnerung_frist_mail_senden(db());

$msg = $result['versendet']
    ? "OK: Erinnerungsmail versendet ({$result['anzahl_antraege']} Anträge, {$result['anzahl_ereignisse']} Ereignisse).\n"
    : "Kein Versand: " . ($result['grund'] ?? 'unbekannt') . "\n";

echo $msg;
error_log('[erinnerung_frist] ' . trim($msg));
