<?php
// ============================================================
// _backend/mitglieder_auth.php
// ============================================================

if (!defined('MK_SESSION_COOKIE')) define('MK_SESSION_COOKIE', 'mk_session');
// War vorher 14 Tage FEST ab Login (kein Inaktivitäts-Logout möglich).
// Jetzt: Sliding-Window-Timeout - jede erkannte Aktivität verlängert die
// Session um erneut MK_SESSION_TTL. Ohne Aktivität läuft sie nach dieser
// Zeit ab. Siehe mitglied_session_pruefen().
//
// Nutzt dieselbe MITGLIED_SESSION_LIFETIME-Konfiguration wie der
// PHP-native Session-Timeout in _backend/auth.php - vorher gab es hier
// eine zweite, unabhängige 30-Minuten-Konstante, wodurch eine Änderung
// des Timeouts (z.B. auf 15 Minuten) den client-seitigen Countdown zwar
// verkürzte, serverseitig aber wirkungslos blieb, da die Mitglieder-
// Authentifizierung nicht über $_SESSION läuft, sondern über dieses
// eigene, datenbankgestützte Token-System.
if (!defined('MK_SESSION_TTL')) {
    define('MK_SESSION_TTL', defined('MITGLIED_SESSION_LIFETIME') ? MITGLIED_SESSION_LIFETIME : 60 * 30);
}
if (!defined('MK_EINLADUNG_TTL'))  define('MK_EINLADUNG_TTL',  60 * 60 * 72);

function email_hash(string $email): string {
    return hash('sha256', strtolower(trim($email)));
}

// ── Session prüfen MIT Sliding-Window-Verlängerung ────────────
// Liefert ['status' => 'ok'|'kein_cookie'|'ungueltig'|'inaktivitaet_abgelaufen',
//          'mitglied' => array|null].
// Bei 'ok' wird laeuft_ab (DB) UND das Cookie automatisch um erneut
// MK_SESSION_TTL verlängert - das ist der eigentliche Sliding-Window-
// Mechanismus. 'inaktivitaet_abgelaufen' erlaubt es aufrufenden Seiten
// (z.B. der Login-Seite), gezielt "wegen Inaktivität abgemeldet"
// anzuzeigen statt einer generischen Login-Aufforderung.
function mitglied_session_pruefen(): array {
    $raw_token = $_COOKIE[MK_SESSION_COOKIE] ?? '';
    if (strlen($raw_token) !== 64) {
        return ['status' => 'kein_cookie', 'mitglied' => null];
    }
    $hash = hash('sha256', $raw_token);

    $stmt = db()->prepare(
        "SELECT m.id, m.tk_id, m.vorname, m.nachname_enc, m.aktiv, s.laeuft_ab
         FROM mitglieder_sessions s
         JOIN mitglieder m ON m.id = s.mitglied_id
         WHERE s.token_hash = ?
         LIMIT 1"
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row || !$row['aktiv']) {
        return ['status' => 'ungueltig', 'mitglied' => null];
    }

    if (strtotime($row['laeuft_ab']) <= time()) {
        // Abgelaufene Session gleich mit aufräumen
        db()->prepare("DELETE FROM mitglieder_sessions WHERE token_hash = ?")->execute([$hash]);
        return ['status' => 'inaktivitaet_abgelaufen', 'mitglied' => null];
    }

    // Aktivität erkannt -> Sliding-Window verlängern (DB + Cookie)
    $neue_ablauf = date('Y-m-d H:i:s', time() + MK_SESSION_TTL);
    db()->prepare("UPDATE mitglieder_sessions SET laeuft_ab = ? WHERE token_hash = ?")
        ->execute([$neue_ablauf, $hash]);
    setcookie(MK_SESSION_COOKIE, $raw_token, [
        'expires'  => time() + MK_SESSION_TTL,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    unset($row['laeuft_ab']);
    return ['status' => 'ok', 'mitglied' => $row];
}

// Bestehende Funktion bleibt für Rückwärtskompatibilität erhalten (alle
// bisherigen Aufrufer, die nur ein Array-oder-null erwarten, funktionieren
// unverändert weiter) - nutzt jetzt intern die Sliding-Window-Prüfung.
function mitglied_aus_session(): ?array {
    return mitglied_session_pruefen()['mitglied'];
}

function mitglied_session_erstellen(int $mitglied_id, int $tk_id): string {
    $token      = bin2hex(random_bytes(32));
    $token_hash = hash('sha256', $token);
    $ablauf     = date('Y-m-d H:i:s', time() + MK_SESSION_TTL);
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    db()->prepare(
        "INSERT INTO mitglieder_sessions
         (mitglied_id, tk_id, token_hash, ip, user_agent, laeuft_ab)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$mitglied_id, $tk_id, $token_hash, $ip, $ua, $ablauf]);
    db()->prepare("DELETE FROM mitglieder_sessions WHERE laeuft_ab < NOW()")->execute();
    setcookie(MK_SESSION_COOKIE, $token, [
        'expires'  => time() + MK_SESSION_TTL,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return $token;
}

function mitglied_logout(): void {
    $raw_token = $_COOKIE[MK_SESSION_COOKIE] ?? '';
    if ($raw_token) {
        $hash = hash('sha256', $raw_token);
        db()->prepare("DELETE FROM mitglieder_sessions WHERE token_hash = ?")->execute([$hash]);
    }
    setcookie(MK_SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function mitglied_login(string $email, string $password): ?array {
    $hash = email_hash($email);
    $stmt = db()->prepare(
        "SELECT id, tk_id, vorname, password_hash, aktiv, einladung_token
         FROM mitglieder WHERE email_hash = ? LIMIT 1"
    );
    $stmt->execute([$hash]);
    $m = $stmt->fetch();
    if (!$m) return null;
    if (!$m['aktiv']) return null;
    if ($m['einladung_token'] !== null) return null;
    if (!password_verify($password, $m['password_hash'])) return null;
    db()->prepare("UPDATE mitglieder SET letzter_login = NOW() WHERE id = ?")->execute([$m['id']]);

    // Ältere Anträge ohne mitglied_id nachträglich verknüpfen
    // Anträge einer beliebigen TK des Mitglieds (Heim-TK + evtl. weitere
    // bei Mehrfachmitgliedschaft, siehe mitglied_tk_ids()) mit derselben
    // E-Mail werden verknüpft.
    $mk_tk_ids = mitglied_tk_ids((int)$m['id']);
    $mk_tk_ph  = implode(',', array_fill(0, count($mk_tk_ids), '?'));
    $email_enc_rows = db()->prepare(
        "SELECT id FROM antraege
         WHERE tk_id IN ({$mk_tk_ph}) AND mitglied_id IS NULL"
    );
    $email_enc_rows->execute($mk_tk_ids);
    $kandidaten = $email_enc_rows->fetchAll();
    foreach ($kandidaten as $row) {
        // E-Mail entschlüsseln und vergleichen
        $enc = db()->prepare("SELECT vc_email_enc FROM antraege WHERE id = ? LIMIT 1");
        $enc->execute([$row['id']]);
        $enc_val = $enc->fetchColumn();
        if ($enc_val && email_hash(decrypt($enc_val)) === $hash) {
            db()->prepare("UPDATE antraege SET mitglied_id = ? WHERE id = ?")
                ->execute([$m['id'], $row['id']]);
        }
    }

    return $m;
}

function mitglied_anlegen(int $tk_id, string $vorname, string $nachname, string $email, string $erstellt_von): int {
    $einladung_token  = bin2hex(random_bytes(32));
    $einladung_ablauf = date('Y-m-d H:i:s', time() + MK_EINLADUNG_TTL);
    $email_h          = email_hash($email);
    $nachname_e       = encrypt($nachname);
    $email_e          = encrypt($email);
    $pw_placeholder   = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare(
        "INSERT INTO mitglieder
         (tk_id, vorname, nachname_enc, vc_email_enc, email_hash,
          password_hash, einladung_token, einladung_ablauf, erstellt_von)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([$tk_id, $vorname, $nachname_e, $email_e, $email_h,
                $pw_placeholder, $einladung_token, $einladung_ablauf, $erstellt_von]);
    return (int)db()->lastInsertId();
}

function mitglied_einladung_aktivieren(string $token, string $password): bool {
    $stmt = db()->prepare(
        "SELECT id FROM mitglieder
         WHERE einladung_token = ? AND einladung_ablauf > NOW() AND aktiv = 1 LIMIT 1"
    );
    $stmt->execute([$token]);
    $m = $stmt->fetch();
    if (!$m) return false;
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare(
        "UPDATE mitglieder SET password_hash = ?, einladung_token = NULL, einladung_ablauf = NULL WHERE id = ?"
    )->execute([$hash, $m['id']]);
    return true;
}

function mitglied_pw_reset_token_erstellen(string $email): ?array {
    $hash = email_hash($email);
    $stmt = db()->prepare(
        "SELECT id, vorname FROM mitglieder
         WHERE email_hash = ? AND aktiv = 1 AND einladung_token IS NULL LIMIT 1"
    );
    $stmt->execute([$hash]);
    $m = $stmt->fetch();
    if (!$m) return null;
    $token  = bin2hex(random_bytes(32));
    $ablauf = date('Y-m-d H:i:s', time() + 3600);
    db()->prepare(
        "UPDATE mitglieder SET pw_reset_token = ?, pw_reset_ablauf = ? WHERE id = ?"
    )->execute([$token, $ablauf, $m['id']]);
    return ['token' => $token, 'vorname' => $m['vorname']];
}

function mitglied_pw_reset_ausfuehren(string $token, string $password): bool {
    $stmt = db()->prepare(
        "SELECT id FROM mitglieder
         WHERE pw_reset_token = ? AND pw_reset_ablauf > NOW() AND aktiv = 1 LIMIT 1"
    );
    $stmt->execute([$token]);
    $m = $stmt->fetch();
    if (!$m) return false;
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare(
        "UPDATE mitglieder SET password_hash = ?, pw_reset_token = NULL, pw_reset_ablauf = NULL WHERE id = ?"
    )->execute([$hash, $m['id']]);
    return true;
}

// ── Mehrfachmitgliedschaft ─────────────────────────────────────
// Ein Mitglied gehört primär EINER "Heim-TK" an (mitglieder.tk_id,
// unverändert wie bisher - u.a. Login, Stammdaten, Standardauswahl).
// Zusätzlich kann es über die Tabelle mitglied_tks weiteren TKs
// zugeordnet sein. mitglied_tk_ids() liefert IMMER die vollständige
// Liste (Heim-TK + alle Zusatz-TKs), damit der Rest des Codes nicht an
// zwei Stellen nachschauen muss.
function mitglied_tk_ids(int $mitglied_id): array {
    $stmt = db()->prepare(
        "SELECT tk_id FROM (
            SELECT tk_id FROM mitglied_tks WHERE mitglied_id = ?
            UNION
            SELECT tk_id FROM mitglieder WHERE id = ?
         ) x ORDER BY tk_id"
    );
    $stmt->execute([$mitglied_id, $mitglied_id]);
    return array_map('intval', array_column($stmt->fetchAll(), 'tk_id'));
}

// Wie mitglied_tk_ids(), aber mit Kürzel/Bezeichnung/Airline statt nur
// der ID - für Anzeige im Portal (Header-Badges, TK-Auswahl beim
// Antrag stellen).
function mitglied_tks_details(int $mitglied_id): array {
    $ids = mitglied_tk_ids($mitglied_id);
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, kuerzel, bezeichnung, airline FROM tks WHERE id IN ({$ph}) ORDER BY kuerzel"
    );
    $stmt->execute($ids);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mitglied_antraege_laden(int $mitglied_id): array {
    // Tag-Status: bei entschiedenem Antrag grundsätzlich den Antragsstatus
    // verwenden - AUSSER der einzelne Tag wurde bereits (teil-)storniert
    // (at.status = 'storno_buero'). Ohne diese Ausnahme würde ein Tag, der
    // z.B. bei einem "genehmigt"-Antrag per Teil-Storno storniert wurde,
    // im Mitgliederportal weiterhin als "genehmigt" angezeigt und wäre im
    // Storno-Dialog fälschlich wieder als "offen" auswählbar - die
    // Stornierung würde für das Mitglied so aussehen, als hätte sie nicht
    // funktioniert.
    $stmt = db()->prepare(
        "SELECT
            a.id, a.veranstaltung, a.zeitraum_von, a.zeitraum_bis,
            a.airline, a.position, a.flugzeugmuster, a.status,
            a.erstellt_am, a.entschieden_am,
            a.tk_id, t.kuerzel AS tk_kuerzel,
            at.tag,
            CASE
                WHEN at.status = 'storno_buero' THEN 'storno_buero'
                WHEN a.status IN ('genehmigt','abgelehnt','abgelehnt_ag','storno_buero')
                THEN a.status
                ELSE at.status
            END AS tag_status
         FROM antraege a
         JOIN antrag_tage at ON at.antrag_id = a.id
         LEFT JOIN tks t ON t.id = a.tk_id
         WHERE a.mitglied_id = ?
         ORDER BY a.id DESC, at.tag ASC"
    );
    $stmt->execute([$mitglied_id]);
    $rows = $stmt->fetchAll();

    $antraege = [];
    foreach ($rows as $r) {
        $aid = $r['id'];
        if (!isset($antraege[$aid])) {
            $antraege[$aid] = [
                'id'             => $aid,
                'veranstaltung'  => $r['veranstaltung'],
                'zeitraum_von'   => $r['zeitraum_von'],
                'zeitraum_bis'   => $r['zeitraum_bis'],
                'airline'        => $r['airline'],
                'position'       => $r['position'],
                'flugzeugmuster' => $r['flugzeugmuster'],
                'status'         => $r['status'],
                'eingereicht_am' => $r['erstellt_am'],
                'entschieden_am' => $r['entschieden_am'],
                'tk_id'          => (int)$r['tk_id'],
                'tk_kuerzel'     => $r['tk_kuerzel'],
                'tage'           => [],
            ];
        }
        $antraege[$aid]['tage'][] = [
            'tag'    => $r['tag'],
            'status' => $r['tag_status'],
        ];
    }
    return array_values($antraege);
}
