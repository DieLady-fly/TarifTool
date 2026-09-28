<?php
// ============================================================
// api/panel_pw_reset.php  –  Self-Service-Passwort-Reset für
// Panel-Benutzer (Büro/Finance/Admin), OHNE Login.
//
// Bewusst aus api/admin.php ausgelagert: api/admin.php enthält
// ausschließlich admin-only Funktionen (Benutzerverwaltung,
// Server-Konfiguration, TK-Provisioning) und kann dadurch
// vollständig auf VPN/Büro-IP beschränkt werden. Diese Datei ist
// die einzige Ausnahme, die öffentlich erreichbar bleiben muss –
// aufgerufen von panel_pw_reset.php und passwort_vergessen.php
// (beide öffentlich, kein VPN nötig).
//
// Endpunkte:
//   POST action=request_pw_reset_panel  – Reset-Link per E-Mail anfordern
//   POST action=do_pw_reset_panel       – neues Passwort per Token setzen
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mail_panel.php';
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

$action = trim($_POST['action'] ?? '');
csrf_check();

switch ($action) {

    // ── Passwort-Reset per E-Mail anfordern ───────────────────
    case 'request_pw_reset_panel':
        if (!rate_limit_ok('pw_reset_panel')) {
            json_err('Zu viele Anfragen. Bitte später erneut versuchen.', 429);
        }
        audit('pw_reset_panel_versuch', null, null);

        $email = clean($_POST['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');

        $stmt = db()->prepare(
            "SELECT id, username FROM panel_users WHERE email = ? AND aktiv = 1 LIMIT 1"
        );
        $stmt->execute([$email]);
        $u = $stmt->fetch();

        if ($u) {
            $token  = bin2hex(random_bytes(32));
            $ablauf = date('Y-m-d H:i:s', time() + 3600);
            db()->prepare(
                "UPDATE panel_users SET pw_reset_token = ?, pw_reset_ablauf = ? WHERE id = ?"
            )->execute([$token, $ablauf, $u['id']]);
            mail_pw_reset_panel($email, $u['username'], $token);
            audit('pw_reset_angefordert', $u['id'], "Panel-User: {$u['username']}");
        }
        // Immer gleiche Antwort – verhindert User-Enumeration über den Response-Inhalt.
        // (Ein minimaler Timing-Unterschied durch den Mailversand bleibt bestehen;
        //  das oben ergänzte Rate-Limit begrenzt, wie oft das pro IP messbar ist.)
        json_ok(['message' => 'Falls die E-Mail-Adresse bekannt ist, wurde ein Reset-Link versandt.']);
        break;

    // ── Passwort-Reset einlösen (Token aus E-Mail) ────────────────
    case 'do_pw_reset_panel':
        if (!rate_limit_ok('pw_reset_panel')) {
            json_err('Zu viele Anfragen. Bitte später erneut versuchen.', 429);
        }

        $token   = clean($_POST['token']       ?? '');
        $neu_pw  = $_POST['neu_passwort']      ?? '';
        $neu_pw2 = $_POST['neu_passwort2']     ?? '';
        if (!$token)               json_err('Token fehlt.');
        if ($neu_pw !== $neu_pw2)  json_err('Die Passwörter stimmen nicht überein.');

        $stmt = db()->prepare(
            "SELECT id, username FROM panel_users
             WHERE pw_reset_token = ? AND pw_reset_ablauf > NOW() AND aktiv = 1 LIMIT 1"
        );
        $stmt->execute([$token]);
        $u = $stmt->fetch();
        if (!$u) json_err('Dieser Link ist ungültig oder abgelaufen.');

        if ($fehler = password_ist_sicher($neu_pw, [$u['username']])) {
            json_err($fehler);
        }

        $hash = password_hash($neu_pw, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare(
            "UPDATE panel_users SET password_hash = ?, pw_reset_token = NULL, pw_reset_ablauf = NULL WHERE id = ?"
        )->execute([$hash, $u['id']]);
        audit('pw_reset_durchgefuehrt', $u['id'], "Panel-User: {$u['username']}");
        json_ok(['message' => 'Passwort erfolgreich geändert. Sie können sich jetzt einloggen.']);
        break;

    default:
        json_err('Unbekannte Aktion.', 400);
}
