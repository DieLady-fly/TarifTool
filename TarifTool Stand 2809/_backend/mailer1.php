<?php
// ============================================================
// _backend/mailer.php  –  Mail-Versand per SMTP
//
// Vorher: PHP mail() (funktionierte auf Strato, weil dort ein lokaler
// Sendmail-Prozess lief). Azure App Service (Linux) hat KEINEN lokalen
// Mailserver - mail() schlägt dort immer fehl ("/usr/sbin/sendmail:
// not found"). Ersetzt durch einen minimalen, reinen PHP-SMTP-Client
// (_smtp_send), der eure tatsächlichen Mailserver-Zugangsdaten benutzt.
//
// Benötigte Konstanten in config.php (Beispielwerte anpassen):
//   define('MAIL_SMTP_HOST', 'smtp.eure-domain.de');
//   define('MAIL_SMTP_PORT', 587);              // 587=STARTTLS, 465=SSL, 25=meist von Azure blockiert
//   define('MAIL_SMTP_SECURE', 'tls');          // 'tls' | 'ssl' | ''
//   define('MAIL_SMTP_USER', 'benutzername');
//   define('MAIL_SMTP_PASS', 'passwort');
// (MAIL_FROM, MAIL_FROM_NAME, MAIL_REPLY_TO bleiben wie bisher.)
// ============================================================

// ── Roher SMTP-Client (keine externen Abhängigkeiten) ─────────
function _smtp_send(string $to, string $subject_encoded, string $headers_raw, string $body): bool {
    if (!defined('MAIL_SMTP_HOST') || !MAIL_SMTP_HOST) {
        error_log('_smtp_send: MAIL_SMTP_HOST nicht konfiguriert - Mail NICHT versendet an ' . $to);
        return false;
    }

    // Empfänger für den SMTP-Envelope einsammeln: To + evtl. Cc-Zeile aus
    // den Headern (SMTP verlangt für JEDEN Empfänger ein eigenes RCPT TO -
    // die Cc:-Kopfzeile selbst bewirkt beim reinen SMTP-Versand noch KEINE
    // Zustellung, sie ist nur eine Anzeige-Information im Mailclient).
    $empfaenger = [_smtp_extract_email($to)];
    if (preg_match('/^Cc:\s*(.+)$/mi', $headers_raw, $m)) {
        foreach (explode(',', $m[1]) as $cc) {
            $addr = _smtp_extract_email(trim($cc));
            if ($addr) $empfaenger[] = $addr;
        }
    }
    $empfaenger = array_values(array_unique(array_filter($empfaenger)));
    if (!$empfaenger) {
        error_log('_smtp_send: keine gültige Empfängeradresse aus "' . $to . '"');
        return false;
    }

    $von_addr = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@example.com';

    try {
        $host = MAIL_SMTP_HOST;
        $port = defined('MAIL_SMTP_PORT') ? (int)MAIL_SMTP_PORT : 587;
        $secure = defined('MAIL_SMTP_SECURE') ? strtolower((string)MAIL_SMTP_SECURE) : 'tls';

        $transport = ($secure === 'ssl') ? 'ssl://' : '';
        $sock = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno, $errstr, 15,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );
        if (!$sock) {
            error_log("_smtp_send: Verbindung zu {$host}:{$port} fehlgeschlagen: {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($sock, 15);

        $read = function () use ($sock): string {
            $data = '';
            while (($line = fgets($sock, 515)) !== false) {
                $data .= $line;
                // Mehrzeilige SMTP-Antworten haben "-" statt " " nach dem Code
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function (string $cmd) use ($sock): void {
            fwrite($sock, $cmd . "\r\n");
        };
        $expect = function (string $resp, string $codes): bool {
            return in_array(substr($resp, 0, 3), array_map('trim', explode(',', $codes)), true);
        };

        $banner = $read();
        if (!$expect($banner, '220')) { fclose($sock); error_log('_smtp_send: kein 220-Banner: ' . trim($banner)); return false; }

        $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $ehlo = $read();
        if (!$expect($ehlo, '250')) { fclose($sock); error_log('_smtp_send: EHLO fehlgeschlagen: ' . trim($ehlo)); return false; }

        if ($secure === 'tls') {
            $write('STARTTLS');
            $tls = $read();
            if (!$expect($tls, '220')) { fclose($sock); error_log('_smtp_send: STARTTLS abgelehnt: ' . trim($tls)); return false; }
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock); error_log('_smtp_send: TLS-Handshake fehlgeschlagen'); return false;
            }
            // Nach STARTTLS erneut EHLO (Pflicht laut RFC 3207)
            $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            $ehlo2 = $read();
            if (!$expect($ehlo2, '250')) { fclose($sock); error_log('_smtp_send: EHLO nach STARTTLS fehlgeschlagen: ' . trim($ehlo2)); return false; }
        }

        if (defined('MAIL_SMTP_USER') && MAIL_SMTP_USER !== '') {
            $write('AUTH LOGIN');
            $r1 = $read();
            if (!$expect($r1, '334')) { fclose($sock); error_log('_smtp_send: AUTH LOGIN abgelehnt: ' . trim($r1)); return false; }
            $write(base64_encode(MAIL_SMTP_USER));
            $r2 = $read();
            if (!$expect($r2, '334')) { fclose($sock); error_log('_smtp_send: SMTP-User abgelehnt: ' . trim($r2)); return false; }
            $write(base64_encode(defined('MAIL_SMTP_PASS') ? MAIL_SMTP_PASS : ''));
            $r3 = $read();
            if (!$expect($r3, '235')) { fclose($sock); error_log('_smtp_send: SMTP-Login fehlgeschlagen (Zugangsdaten prüfen): ' . trim($r3)); return false; }
        }

        $write('MAIL FROM:<' . $von_addr . '>');
        $rf = $read();
        if (!$expect($rf, '250')) { fclose($sock); error_log('_smtp_send: MAIL FROM abgelehnt: ' . trim($rf)); return false; }

        foreach ($empfaenger as $addr) {
            $write('RCPT TO:<' . $addr . '>');
            $rt = $read();
            if (!$expect($rt, '250,251')) { fclose($sock); error_log("_smtp_send: RCPT TO <{$addr}> abgelehnt: " . trim($rt)); return false; }
        }

        $write('DATA');
        $rd = $read();
        if (!$expect($rd, '354')) { fclose($sock); error_log('_smtp_send: DATA abgelehnt: ' . trim($rd)); return false; }

        $to_header = 'To: ' . $to;
        $subject_header = 'Subject: ' . $subject_encoded;
        // Führende Punkte auf eigenen Zeilen escapen (SMTP-Transparenz, RFC 5321 4.5.2)
        $payload = $to_header . "\r\n" . $subject_header . "\r\n" . $headers_raw . "\r\n\r\n" . $body;
        $payload = preg_replace('/\r\n\./', "\r\n..", $payload);
        $write($payload . "\r\n.");
        $rdata = $read();
        if (!$expect($rdata, '250')) { fclose($sock); error_log('_smtp_send: Zustellung abgelehnt: ' . trim($rdata)); return false; }

        $write('QUIT');
        fclose($sock);
        return true;
    } catch (Throwable $e) {
        error_log('_smtp_send Exception: ' . $e->getMessage());
        return false;
    }
}

// ── Hilfsfunktion: reine E-Mail-Adresse aus "Name <adresse>" extrahieren ──
function _smtp_extract_email(string $raw): string {
    if (preg_match('/<([^>]+)>/', $raw, $m)) return trim($m[1]);
    $raw = trim($raw);
    return filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : '';
}

function _mail_send(string $to, string $subject, string $html, string $cc = ''): bool {
    $boundary = md5(uniqid('', true));
    $plain    = wordwrap(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)), 76, "\n");
    $headers  = implode("\r\n", array_filter([
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_REPLY_TO,
        $cc ? 'Cc: ' . $cc : '',
        'X-Mailer: FreistellungsSystem/2.0',
        'X-Priority: 3',
    ]));
    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n\r\n";
    $body .= "--{$boundary}--";
    $enc_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return _smtp_send($to, $enc_subject, $headers, $body);
}

// ── Mail-Versand MIT Dateianhang (z.B. Freistellungsliste-Excel) ─
// $attachments = [ ['path' => '/tmp/datei.xlsx', 'filename' => 'Anzeigename.xlsx',
//                    'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], … ]
function _mail_send_attach(string $to, string $subject, string $html, string $cc = '', array $attachments = []): bool {
    if (empty($attachments)) {
        return _mail_send($to, $subject, $html, $cc);
    }

    $mixedBoundary = md5(uniqid('mixed', true));
    $altBoundary   = md5(uniqid('alt', true));

    $plain = wordwrap(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)), 76, "\n");

    $headers = implode("\r\n", array_filter([
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        'Reply-To: ' . MAIL_REPLY_TO,
        $cc ? 'Cc: ' . $cc : '',
        'X-Mailer: FreistellungsSystem/2.0',
        'X-Priority: 3',
    ]));

    $body  = "--{$mixedBoundary}\r\n";
    $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";
    $body .= "--{$altBoundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n\r\n";
    $body .= "--{$altBoundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n\r\n";
    $body .= "--{$altBoundary}--\r\n";

    foreach ($attachments as $att) {
        if (empty($att['path']) || !is_file($att['path'])) continue;
        $mime = $att['mime'] ?? 'application/octet-stream';
        $name = $att['filename'] ?? basename($att['path']);
        $data = chunk_split(base64_encode(file_get_contents($att['path'])));

        $body .= "--{$mixedBoundary}\r\n";
        $body .= "Content-Type: {$mime}; name=\"{$name}\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n";
        $body .= $data . "\r\n";
    }
    $body .= "--{$mixedBoundary}--";

    $enc_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return _smtp_send($to, $enc_subject, $headers, $body);
}

function _mail_wrap(string $subtitle, string $content): string {
    return <<<HTML
<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">
<style>
body{margin:0;padding:24px;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif}
.wrap{max-width:580px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.1)}
.hd{background:#0f2744;color:#fff;padding:28px 32px;border-bottom:3px solid #c8a84b}
.hd h1{margin:0;font-size:18px;font-weight:700;letter-spacing:.3px}
.hd p{margin:4px 0 0;font-size:12px;opacity:.6}
.bd{padding:32px}
.bd p{color:#333;line-height:1.65;margin:0 0 12px;font-size:15px}
.bd table{width:100%;border-collapse:collapse;margin:16px 0;font-size:14px}
.bd table td{padding:8px 12px;border-bottom:1px solid #f0f2f5}
.bd table td:first-child{font-weight:700;color:#555;width:42%;background:#f8f9fb}
.btn{display:inline-block;padding:13px 28px;border-radius:7px;text-decoration:none;font-weight:700;font-size:15px;margin:6px 4px 6px 0}
.btn-ok{background:#1e7e34;color:#fff}
.btn-no{background:#c0392b;color:#fff}
.ft{background:#f8f9fb;border-top:1px solid #eee;padding:14px 32px;font-size:11px;color:#aaa}
</style></head><body>
<div class="wrap">
<div class="hd"><h1>Freistellungssystem Tarif</h1><p>{$subtitle}</p></div>
<div class="bd">{$content}</div>
<div class="ft">Diese Nachricht wurde automatisch generiert. Bitte nicht direkt antworten.</div>
</div></body></html>
HTML;
}

// ── Mail: Eingangsbestätigung → Antragsteller ────────────────
function mail_eingang(
    string  $to,
    string  $vorname,
    string  $token,
    ?string $veranstaltung   = null,
    ?string $bezeichnung     = null,
    array   $tage            = []
): void {
    $details_html = '';
    if ($veranstaltung || !empty($tage)) {
        $rows = '';
        if ($veranstaltung) {
            $label = htmlspecialchars($veranstaltung);
            if ($bezeichnung) $label .= ' (' . htmlspecialchars($bezeichnung) . ')';
            $rows .= '<tr><td>Veranstaltung</td><td>' . $label . '</td></tr>';
        }
        if (!empty($tage)) {
            $tage_fmt = array_map(function($t) {
                [$y,$m,$d] = explode('-', $t);
                return "$d.$m.$y";
            }, $tage);
            $rows .= '<tr><td>Beantrage Tage</td><td>' . implode('<br>', $tage_fmt) . '</td></tr>';
        }
        $details_html = '<table style="margin:16px 0">' . $rows . '</table>';
    }

    $html = _mail_wrap('Ihr Antrag ist eingegangen', <<<HTML
<p>Hallo {$vorname},</p>
<p>dein Freistellungsantrag wurde erfolgreich eingereicht und wird jetzt durch das Büro geprüft.</p>
{$details_html}
<p><strong>Den Status kannst du jederzeit in deinem Mitglieder-Portal einsehen.</strong></p>
<p>Mit freundlichen Grüßen<br>Freistellungssystem Tarif</p>
HTML);
    _mail_send($to, 'Freistellungsantrag – Eingang bestätigt', $html);
}

// ── Mail: Info "neuer Antrag eingegangen" → Büro ─────────────────
// Reine Eingangs-Information ohne Genehmigen/Ablehnen-Buttons/Token –
// bewusst getrennt von mail_referent(), damit das Büro nicht zwei
// unterschiedliche Mails bekommt, die beide wie eine Entscheidungs-
// anfrage aussehen (z.B. bei TK-Sitzungen, wo das Büro ohnehin
// zusätzlich die Freigabe-Mail mit Token erhält).
function mail_buero_neuer_antrag(string $to, array $a): void {
    $tk_name = $a['tk_bezeichnung'] ?? '';

    $termine_rows = '';
    foreach (($a['termine'] ?? []) as $t) {
        $von = date('d.m.Y', strtotime($t['von']));
        $bis = date('d.m.Y', strtotime($t['bis']));
        $zeitraum = ($t['von'] === $t['bis']) ? $von : "{$von} – {$bis}";
        $typ_label = htmlspecialchars($t['typ']);
        if ($t['typ'] === 'sonstige' && !empty($t['bezeichnung'])) {
            $typ_label .= ' (' . htmlspecialchars($t['bezeichnung']) . ')';
        }
        $termine_rows .= '<tr><td style="padding:4px 8px">' . $zeitraum . '</td><td style="padding:4px 8px">' . $typ_label . '</td></tr>';
    }
    $termine_tabelle = '<tr><td>Termine</td><td><table style="border-collapse:collapse;width:100%;font-size:13px">'
        . '<tr style="background:#f0f2f5"><th style="padding:4px 8px;text-align:left">Zeitraum</th><th style="padding:4px 8px;text-align:left">Veranstaltung</th></tr>'
        . $termine_rows
        . '</table></td></tr>';

    $html = _mail_wrap('Neuer Antrag eingegangen', '<p>Hallo,</p>'
        . '<p>ein neuer Freistellungsantrag ist eingegangen:</p>'
        . '<table>'
        . '<tr><td>Name</td><td>' . htmlspecialchars($a['vorname'] . ' ' . $a['nachname']) . '</td></tr>'
        . '<tr><td>Tarifkommission</td><td>' . htmlspecialchars($tk_name) . '</td></tr>'
        . '<tr><td>Airline</td><td>' . htmlspecialchars($a['airline']) . '</td></tr>'
        . '<tr><td>Position</td><td>' . htmlspecialchars($a['position']) . '</td></tr>'
        . '<tr><td>Flugzeugmuster</td><td>' . htmlspecialchars($a['flugzeugmuster']) . '</td></tr>'
        . $termine_tabelle
        . '</table>'
        . '<p style="font-size:12px;color:#999">Dies ist eine reine Information. Die Entscheidung erfolgt ggf. separat durch den zuständigen Tarifreferenten oder das Büro.</p>'
    );
    _mail_send($to, 'Neuer Freistellungsantrag eingegangen', $html);
}

// ── Mail: Freigabe-Anfrage → Tarifreferent ───────────────────
function mail_referent(string $to, string $ref_name, array $a): void {
    $approve = BASE_URL . '/api/entscheidung.php?token=' . urlencode($a['token']) . '&action=genehmigt';
    $reject  = BASE_URL . '/api/entscheidung.php?token=' . urlencode($a['token']) . '&action=abgelehnt';
    $tk_name = $a['tk_bezeichnung'] ?? '';
    // Termine als Tabellenzeilen aufbereiten
    $termine_rows = '';
    foreach (($a['termine'] ?? []) as $t) {
        $von = date('d.m.Y', strtotime($t['von']));
        $bis = date('d.m.Y', strtotime($t['bis']));
        $zeitraum = ($t['von'] === $t['bis']) ? $von : "{$von} – {$bis}";
        $typ_label = htmlspecialchars($t['typ']);
        if ($t['typ'] === 'sonstige' && !empty($t['bezeichnung'])) {
            $typ_label .= ' (' . htmlspecialchars($t['bezeichnung']) . ')';
        }
        $termine_rows .= '<tr><td style="padding:4px 8px">' . $zeitraum . '</td><td style="padding:4px 8px">' . $typ_label . '</td></tr>';
    }
    $termine_tabelle = '<tr><td>Termine</td><td><table style="border-collapse:collapse;width:100%;font-size:13px">'
        . '<tr style="background:#f0f2f5"><th style="padding:4px 8px;text-align:left">Zeitraum</th><th style="padding:4px 8px;text-align:left">Veranstaltung</th></tr>'
        . $termine_rows
        . '</table></td></tr>';

    $html = _mail_wrap('Neuer Antrag – Entscheidung erforderlich', '<p>Hallo ' . $ref_name . ',</p>'
        . '<p>Ein neuer Freistellungsantrag wartet auf Ihre Entscheidung:</p>'
        . '<table>'
        . '<tr><td>Name</td><td>' . $a['vorname'] . ' ' . $a['nachname'] . '</td></tr>'
        . '<tr><td>Tarifkommission</td><td>' . $tk_name . '</td></tr>'
        . '<tr><td>Airline</td><td>' . $a['airline'] . '</td></tr>'
        . '<tr><td>Position</td><td>' . $a['position'] . '</td></tr>'
        . '<tr><td>Flugzeugmuster</td><td>' . $a['flugzeugmuster'] . '</td></tr>'
        . $termine_tabelle
        . '</table>'
        . '<p>Bitte treffen Sie Ihre Entscheidung:</p>'
        . '<table cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 10px"><tr>'
        . '<td style="background:#1e7e34;border-radius:7px">'
        . '<a href="' . $approve . '" style="display:inline-block;padding:14px 30px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;font-family:Arial,Helvetica,sans-serif">✓ Genehmigen</a>'
        . '</td>'
        . '<td style="width:24px;line-height:1px;font-size:1px">&nbsp;</td>'
        . '<td style="background:#c0392b;border-radius:7px">'
        . '<a href="' . $reject  . '" style="display:inline-block;padding:14px 30px;color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;font-family:Arial,Helvetica,sans-serif">✕ Ablehnen</a>'
        . '</td>'
        . '</tr></table>'
        . '<p style="font-size:12px;color:#999">Diese Links öffnen eine Bestätigungsseite.</p>'
    );
    _mail_send($to, 'Freistellungsantrag – Ihre Entscheidung ist gefragt', $html);
}

// ── Mail: Zwischenbescheid (intern genehmigt) → Antragsteller ───
// Wird verschickt, wenn ein Antrag per Token-Link intern genehmigt
// wurde (Status freigabe_buero) – NICHT bei der finalen Entscheidung
// (dafür ist mail_ergebnis() zuständig, sobald der Arbeitgeber
// geantwortet hat).
function mail_zwischenbescheid(
    string  $to,
    string  $vorname,
    ?string $veranstaltung = null,
    array   $tage          = []   // ['2026-03-26', …] ISO-Daten
): void {
    $farbe = '#1d4ed8';
    $badge = "<span style='display:inline-block;padding:6px 18px;border-radius:24px;background:{$farbe};color:#fff;font-size:16px;font-weight:700;letter-spacing:.5px'>◐ INTERN GENEHMIGT</span>";

    $details_html = '';
    if ($veranstaltung || !empty($tage)) {
        $rows = '';
        if ($veranstaltung) $rows .= '<tr><td>Veranstaltung</td><td>' . htmlspecialchars($veranstaltung) . '</td></tr>';
        if (!empty($tage)) {
            $tage_fmt = array_map(function($t) {
                [$y,$m,$d] = explode('-', $t);
                return "$d.$m.$y";
            }, $tage);
            $rows .= '<tr><td>Freistellungstage</td><td>' . implode('<br>', $tage_fmt) . '</td></tr>';
        }
        $details_html = '<table style="margin:16px 0">' . $rows . '</table>';
    }

    $html = _mail_wrap('Zwischenbescheid zu deinem Freistellungsantrag', <<<HTML
<p>Hallo {$vorname},</p>
<p>dein Freistellungsantrag wurde <strong>intern genehmigt</strong>.</p>
<p>{$badge}</p>
<p>Das Büro wird ihn im nächsten Schritt beim Arbeitgeber beantragen. Du bekommst automatisch Bescheid, sobald die endgültige Entscheidung vorliegt.</p>
{$details_html}
<p>Mit freundlichen Grüßen<br>Dein Freistellungssystem Tarif</p>
HTML);
    _mail_send($to, 'Freistellungsantrag – Zwischenbescheid: intern genehmigt', $html);
}

// ── Mail: Ergebnis → Antragsteller ───────────────────────────
function mail_ergebnis(
    string  $to,
    string  $vorname,
    string  $status,
    ?string $notiz           = null,
    ?string $veranstaltung   = null,
    ?string $freistellungscode = null,
    array   $tage            = []   // ['2026-03-26', …] ISO-Daten
): void {
    $ok      = ($status === 'genehmigt');
    $ag      = ($status === 'abgelehnt_ag');
    $storno  = ($status === 'storno_buero');
    $farbe   = $ok ? '#1e7e34' : ($storno ? '#7a5c00' : '#c0392b');
    $symbol  = $ok ? '✓' : ($storno ? '⊘' : '✕');
    $label   = $ok ? 'GENEHMIGT' : ($storno ? 'STORNIERT' : 'ABGELEHNT');
    $text    = $ok
        ? 'Dein Freistellungsantrag wurde vom Arbeitgeber genehmigt.'
        : ($ag
            ? 'Dein Freistellungsantrag wurde vom Arbeitgeber abgelehnt.'
            : ($storno
                ? 'Deine genehmigte Freistellung wurde durch das Büro <strong>storniert</strong>.'
                : 'Dein Freistellungsantrag wurde vom VC-Büro abgelehnt.'));
    $notiz_html = $notiz
        ? '<p><strong>Begründung / Hinweis:</strong> ' . htmlspecialchars($notiz) . '</p>'
        : '';

    // Freistellungsdetails (Veranstaltung, Symbol, Tage)
    $details_html = '';
    if ($veranstaltung || $freistellungscode || !empty($tage)) {
        $rows = '';
        if ($veranstaltung)     $rows .= '<tr><td>Veranstaltung</td><td>' . htmlspecialchars($veranstaltung) . '</td></tr>';
        if ($freistellungscode) $rows .= '<tr><td>Symbol</td><td><strong>' . htmlspecialchars($freistellungscode) . '</strong></td></tr>';
        if (!empty($tage)) {
            $tage_fmt = array_map(function($t) {
                [$y,$m,$d] = explode('-', $t);
                return "$d.$m.$y";
            }, $tage);
            $rows .= '<tr><td>Freistellungstage</td><td>' . implode('<br>', $tage_fmt) . '</td></tr>';
        }
        $details_html = '<table style="margin:16px 0">' . $rows . '</table>';
    }

    $badge = "<span style='display:inline-block;padding:6px 18px;border-radius:24px;background:{$farbe};color:#fff;font-size:16px;font-weight:700;letter-spacing:.5px'>{$symbol} {$label}</span>";
    $subtitle = $ok ? 'Ergebnis deines Freistellungsantrags' : ($storno ? 'Deine Freistellung wurde storniert' : 'Ergebnis deines Freistellungsantrags');
    $html = _mail_wrap($subtitle, <<<HTML
<p>Hallo {$vorname},</p>
<p>dein Freistellungsantrag wurde bearbeitet:</p>
<p>{$badge}</p>
<p>{$text}</p>
{$details_html}
{$notiz_html}
<p>Mit freundlichen Grüßen<br>Dein Freistellungssystem Tarif</p>
HTML);
    $subject = $ok ? 'Freistellungsantrag – Genehmigt ✓' : ($storno ? 'Deine Freistellung wurde storniert' : 'Freistellungsantrag – Abgelehnt');
    _mail_send($to, $subject, $html);
}

// ── Mail: Storno-Benachrichtigung → Flugbetrieb ──────────────
// Wird vom Büro nach einer Stornierung manuell ausgelöst.
// $to        = Empfänger-Adresse des Flugbetriebs
// $cc        = CC-Adresse Tarifteam (leer = kein CC)
// $from_name = Name des Bearbeiters (wird als Reply-To genutzt;
//              Absender bleibt MAIL_FROM wegen Domain-Zwang beim
//              SMTP-Server)
// $subject   = fertig gebauter Betreff
// $body_text = fertiger Plaintext-Body
//
// Gibt true bei Erfolg zurück, false bei Fehler (siehe error_log).
function mail_storno_flugbetrieb(
    string $to,
    string $cc,
    string $from_name,
    string $from_email,
    string $subject,
    string $body_text
): bool {
    // Der Absender muss zur SMTP-Domain passen (MAIL_FROM).
    // Den Bearbeiter tragen wir als Reply-To ein, damit Antworten
    // direkt an ihn gehen.
    $html = _mail_wrap(
        'Stornierungsmitteilung',
        '<p>' . nl2br(htmlspecialchars($body_text)) . '</p>'
        . ($from_email
            ? '<p style="font-size:12px;color:#999;margin-top:24px">'
              . 'Antworten bitte direkt an: '
              . '<a href="mailto:' . htmlspecialchars($from_email) . '">'
              . htmlspecialchars($from_name . ' &lt;' . $from_email . '&gt;')
              . '</a></p>'
            : '')
    );

    $boundary = md5(uniqid('', true));
    $plain    = wordwrap(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html)), 76, "\n");

    $header_lines = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
        // Antworten gehen an den Bearbeiter, nicht an das Systempostfach
        'Reply-To: ' . ($from_email
            ? ('=?UTF-8?B?' . base64_encode($from_name) . '?= <' . $from_email . '>')
            : MAIL_REPLY_TO),
        'X-Sender-Name: ' . $from_name,   // zur Dokumentation im Header
        'X-Mailer: FreistellungsSystem/2.0',
        'X-Priority: 3',
    ];
    if ($cc !== '') {
        $header_lines[] = 'Cc: ' . $cc;
    }

    $headers  = implode("\r\n", $header_lines);

    $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode(wordwrap($body_text, 76, "\n")) . "\r\n\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n\r\n";
    $body .= "--{$boundary}--";

    $enc_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return _smtp_send($to, $enc_subject, $headers, $body);
}
