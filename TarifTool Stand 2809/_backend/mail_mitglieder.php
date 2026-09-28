<?php
// ============================================================
// _backend/mail_mitglieder.php
// E-Mail-Funktionen für das Mitglieder-Portal.
// In bootstrap.php einbinden:
//   require_once __DIR__ . '/mail_mitglieder.php';
// ============================================================

/**
 * Einladungsmail an neues Mitglied.
 * Der Link führt zu /mitglieder_login.php?einladung=TOKEN
 */
function mail_einladung_mitglied(
    string $email,
    string $vorname,
    string $nachname,
    string $tk_bezeichnung,
    string $token
): void {
    $base_url      = rtrim(BASE_URL ?? '', '/');
    $link          = $base_url . '/mitglieder_login.php?einladung=' . urlencode($token);
    $ablauf_std    = intdiv(MK_EINLADUNG_TTL, 3600);

    $subject = "Einladung: Freistellungssystem Tarif – {$tk_bezeichnung}";
    $body    = <<<TEXT
Hallo {$vorname} {$nachname},

du wurdest als Mitglied der Tarifkommission „{$tk_bezeichnung}" im
Freistellungssystem Tarif registriert.

Bitte richte dein persönliches Passwort über den folgenden Link ein:

  {$link}

Dieser Link ist {$ablauf_std} Stunden gültig. Sollte er abgelaufen sein,
wende dich bitte an deinen Administrator.

Mit freundlichen Grüßen
Dein Freistellungssystem
TEXT;

    _send_mail($email, "{$vorname} {$nachname}", $subject, $body);
}

/**
 * Passwort-Reset-Mail.
 */
function mail_pw_reset_mitglied(
    string $email,
    string $vorname,
    string $token
): void {
    $base_url = rtrim(BASE_URL ?? '', '/');
    $link     = $base_url . '/mitglieder_login.php?pw_reset=' . urlencode($token);

    $subject = 'Passwort zurücksetzen – Freistellungssystem';
    $body    = <<<TEXT
Hallo {$vorname},

du hast eine Passwort-Zurücksetzen-Anfrage gestellt.
Klicke auf den folgenden Link, um ein neues Passwort festzulegen:

  {$link}

Dieser Link ist 1 Stunde gültig.

Falls du keine Anfrage gestellt hast, ignoriere bitte diese E-Mail.

Mit freundlichen Grüßen
Dein Freistellungssystem
TEXT;

    _send_mail($email, $vorname, $subject, $body);
}

/**
 * Interne Hilfsfunktion – nutzt jetzt den SMTP-Client aus mailer.php
 * (_smtp_send) statt PHP mail(), da Azure App Service keinen lokalen
 * Mailserver hat. mailer.php muss vor dieser Datei geladen sein
 * (siehe bootstrap.php - require_once-Reihenfolge).
 */
function _send_mail(
    string $to_email,
    string $to_name,
    string $subject,
    string $body
): void {
    $from_email = defined('MAIL_FROM')       ? MAIL_FROM       : 'noreply@example.com';
    $from_name  = defined('MAIL_FROM_NAME')  ? MAIL_FROM_NAME  : 'Freistellungssystem';

    $headers  = "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "Reply-To: {$from_email}\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: 8bit\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "X-Mailer: FreistellungSystem/2.0\r\n";

    $to = "{$to_name} <{$to_email}>";
    $enc_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    _smtp_send($to, $enc_subject, rtrim($headers, "\r\n"), $body);
}
