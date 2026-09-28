<?php
// ============================================================
// _backend/config.php  –  Zentrale Konfiguration
//
// Geheimnisse (Passwörter, Keys) liegen NICHT mehr im Code, sondern
// als "Application Settings" (Umgebungsvariablen) in Azure App Service.
// Dort werden sie verschlüsselt gespeichert und nicht im Repo/Deployment
// mit ausgeliefert.
//
// Einrichtung in Azure: Portal -> App Service -> Einstellungen ->
// Umgebungsvariablen -> "+ Hinzufügen" für jeden der Werte unten
// (Name exakt wie in getenv('...') links, z.B. DB_PASS).
// Nach dem Speichern startet die App automatisch neu.
//
// Alternativ per Azure CLI, z.B.:
//   az webapp config appsettings set --name <APP_NAME> \
//     --resource-group <RESOURCE_GROUP> \
//     --settings DB_PASS="..." ENCRYPTION_KEY="..." MAIL_SMTP_PASS="..."
// ============================================================

// Liest die erste gesetzte von mehreren möglichen Umgebungsvariablen -
// erlaubt sowohl eigene, manuell angelegte Namen (DB_HOST) als auch die
// von Azures "Service Connector" automatisch erzeugten Namen
// (AZURE_MYSQL_HOST etc., Schreibweise variiert je nach Integrationsweg
// zwischen AZURE_MYSQL_USER/AZURE_MYSQL_USERNAME bzw.
// AZURE_MYSQL_NAME/AZURE_MYSQL_DBNAME - deshalb mehrere Fallbacks pro Wert).
function env_required(string ...$namen): string {
    foreach ($namen as $name) {
        $val = getenv($name);
        if ($val !== false && trim($val) !== '') {
            return trim($val);
        }
    }
    // Absichtlich hart abbrechen statt mit leerem Wert weiterzulaufen -
    // sonst fällt ein fehlendes Setting erst als kryptischer DB-/Mail-
    // Fehler später auf, statt sofort und eindeutig hier.
    http_response_code(500);
    error_log('Fehlende Umgebungsvariable(n): ' . implode(' / ', $namen));
    die('Server-Konfigurationsfehler. Bitte Administrator kontaktieren.');
}
function env_optional(string ...$namen): ?string {
    foreach ($namen as $name) {
        $val = getenv($name);
        if ($val !== false && trim($val) !== '') {
            return trim($val);
        }
    }
    return null;
}

define('DB_HOST',    env_required('DB_HOST', 'AZURE_MYSQL_HOST'));
define('DB_NAME',    env_required('DB_NAME', 'AZURE_MYSQL_NAME', 'AZURE_MYSQL_DBNAME'));
define('DB_USER',    env_required('DB_USER', 'AZURE_MYSQL_USER', 'AZURE_MYSQL_USERNAME'));
define('DB_PASS',    env_required('DB_PASS', 'AZURE_MYSQL_PASSWORD'));
define('DB_CHARSET', env_optional('DB_CHARSET') ?? 'utf8mb4');

// NIEMALS ändern – verschlüsselte Daten werden sonst unleserlich!
define('ENCRYPTION_KEY', env_required('ENCRYPTION_KEY'));

// Base-URL
define('BASE_URL', env_required('BASE_URL'));

// Configuration Mail-Server
// MAIL_FROM, MAIL_FROM_NAME und MAIL_REPLY_TO können im Admin-Panel
// überschrieben werden (gespeichert in app_settings). Die DB-Werte haben
// Vorrang vor Umgebungsvariablen; nur wenn kein DB-Eintrag existiert,
// werden Umgebungsvariable bzw. Fallback-Wert verwendet.
// _mail_setting() wird nur einmal aufgerufen und cached das PDO-Objekt nicht –
// db() ist nach dem define('DB_*') oben immer nutzbar.
function _mail_setting(string $key, string $env_name, string $fallback): string {
    static $cache = null;
    if ($cache === null) {
        try {
            $pdo   = db();
            $stmt  = $pdo->query(
                "SELECT setting_key, setting_value FROM app_settings
                  WHERE setting_key IN ('mail_from','mail_from_name','mail_reply_to')"
            );
            $cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            // Tabelle noch nicht vorhanden (z. B. frische Installation) –
            // Fehler unterdrücken und leeres Array als Cache verwenden.
            $cache = [];
        }
    }
    if (isset($cache[$key]) && $cache[$key] !== '') {
        return $cache[$key];
    }
    return env_optional($env_name) ?? $fallback;
}

define('MAIL_FROM',      _mail_setting('mail_from',      'MAIL_FROM',      'tarif@vcockpit.de'));
define('MAIL_FROM_NAME', _mail_setting('mail_from_name', 'MAIL_FROM_NAME', 'VC Büro Tarif'));
define('MAIL_REPLY_TO',  _mail_setting('mail_reply_to',  'MAIL_REPLY_TO',  'tarif@vcockpit.de'));
define('MAIL_SMTP_HOST', env_required('MAIL_SMTP_HOST'));
define('MAIL_SMTP_PORT', (int) (env_optional('MAIL_SMTP_PORT') ?? 587));
define('MAIL_SMTP_USER', env_required('MAIL_SMTP_USER'));
define('MAIL_SMTP_PASS', env_required('MAIL_SMTP_PASS'));

// Session Handling
define('SESSION_LIFETIME',    (int) (env_optional('SESSION_LIFETIME') ?? 1800));
define('RATE_LIMIT_PER_HOUR', (int) (env_optional('RATE_LIMIT_PER_HOUR') ?? 10));
date_default_timezone_set('Europe/Berlin');
