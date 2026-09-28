<?php
// ============================================================
// _backend/mail_panel.php
// E-Mail-Funktionen für Panel-Benutzer (Büro / Admin).
// Analog zu mail_mitglieder.php.
// In bootstrap.php einbinden:
//   require_once __DIR__ . '/mail_panel.php';
// ============================================================

/**
 * Einladungsmail an neuen Panel-Benutzer.
 * Der Link führt zu /panel_pw_reset.php?pw_reset=TOKEN
 */
function mail_einladung_panel(
    string $email,
    string $username,
    string $token
): void {
    $base_url   = rtrim(BASE_URL ?? '', '/');
    $link       = $base_url . '/panel_pw_reset.php?pw_reset=' . urlencode($token);
    $ablauf_std = defined('PANEL_EINLADUNG_TTL') ? intdiv(PANEL_EINLADUNG_TTL, 3600) : 48;

    $subject = 'Einladung – Freistellungssystem Panel-Zugang';

    $html = _panel_mail_wrap('Ihr Panel-Zugang', <<<HTML
<p>Hallo {$username},</p>
<p>Für Sie wurde ein Zugang zum Freistellungssystem eingerichtet.</p>
<p>Bitte legen Sie Ihr persönliches Passwort über den folgenden Link fest:</p>
<p style="margin:24px 0">
  <a href="{$link}" class="btn btn-ok">Passwort festlegen →</a>
</p>
<p style="font-size:13px;color:#666">
  Oder kopieren Sie diesen Link in Ihren Browser:<br>
  <span style="font-family:monospace;font-size:12px;color:#0f2744">{$link}</span>
</p>
<p style="font-size:13px;color:#999">Dieser Link ist {$ablauf_std} Stunden gültig.</p>
<p>Mit freundlichen Grüßen<br>Freistellungssystem Tarif</p>
HTML);

    _panel_mail_send($email, $username, $subject, $html);
}

/**
 * Passwort-Reset-Mail für Panel-Benutzer.
 * Der Link führt zu /panel_pw_reset.php?pw_reset=TOKEN
 * (bewusst NICHT buero_login.php - das ist serverseitig/nginx auf die
 * Büro-VPN-IP beschränkt, siehe location = /buero_login.php. Diese Seite
 * hier muss dagegen von überall erreichbar sein, sonst könnten sich
 * Büro-Nutzer außerhalb des VPNs nie ein Passwort setzen/zurücksetzen -
 * genau wie api/panel_pw_reset.php im Backend bereits bewusst von
 * api/admin.php getrennt ist.)
 */
function mail_pw_reset_panel(
    string $email,
    string $username,
    string $token
): void {
    $base_url = rtrim(BASE_URL ?? '', '/');
    $link     = $base_url . '/panel_pw_reset.php?pw_reset=' . urlencode($token);

    $subject = 'Passwort zurücksetzen – Freistellungssystem';

    $html = _panel_mail_wrap('Passwort zurücksetzen', <<<HTML
<p>Hallo {$username},</p>
<p>Sie haben eine Passwort-Zurücksetzen-Anfrage gestellt.</p>
<p>Klicken Sie auf den folgenden Link, um ein neues Passwort festzulegen:</p>
<p style="margin:24px 0">
  <a href="{$link}" class="btn btn-ok">Neues Passwort festlegen →</a>
</p>
<p style="font-size:13px;color:#666">
  Oder kopieren Sie diesen Link in Ihren Browser:<br>
  <span style="font-family:monospace;font-size:12px;color:#0f2744">{$link}</span>
</p>
<p style="font-size:13px;color:#999">Dieser Link ist 1 Stunde gültig.</p>
<p>Falls Sie keine Anfrage gestellt haben, ignorieren Sie bitte diese E-Mail.</p>
<p>Mit freundlichen Grüßen<br>Freistellungssystem Tarif</p>
HTML);

    _panel_mail_send($email, $username, $subject, $html);
}

// ── Interne Hilfsfunktionen ───────────────────────────────────

function _panel_mail_wrap(string $subtitle, string $content): string {
    return <<<HTML
<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
<style>
body{margin:0;padding:24px;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)}
.hd{background:#0f2744;color:#fff;padding:28px 32px;border-bottom:3px solid #c8a84b}
.hd h1{margin:0;font-size:18px;font-weight:700;letter-spacing:.3px}
.hd p{margin:4px 0 0;font-size:12px;opacity:.6}
.bd{padding:32px}
.bd p{color:#333;line-height:1.65;margin:0 0 12px;font-size:15px}
.btn{display:inline-block;padding:13px 28px;border-radius:7px;text-decoration:none;font-weight:700;font-size:15px}
.btn-ok{background:#1e7e34;color:#fff}
.ft{background:#f8f9fb;border-top:1px solid #eee;padding:14px 32px;font-size:11px;color:#aaa}
</style></head><body>
<div class="wrap">
<div class="hd"><h1>Freistellungssystem Tarif</h1><p>{$subtitle}</p></div>
<div class="bd">{$content}</div>
<div class="ft">Diese Nachricht wurde automatisch generiert. Bitte nicht direkt antworten.</div>
</div></body></html>
HTML;
}

function _panel_mail_send(
    string $to_email,
    string $to_name,
    string $subject,
    string $html
): void {
    // _mail_send($to, $subject, $html) aus mailer.php
    _mail_send("{$to_name} <{$to_email}>", $subject, $html);
}
