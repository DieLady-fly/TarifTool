<?php
// ============================================================
// api/admin.php  –  Backend für das Admin-Panel (nur 'admin'-Rolle)
// Ergänzt um: Mitglieder-Verwaltung, TK-Provisioning
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/tk_provision.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
require_once __DIR__ . '/../_backend/mail_mitglieder.php';
require_once __DIR__ . '/../_backend/mail_panel.php';
send_security_headers();
session_start_secure();
header('Content-Type: application/json; charset=utf-8');

$action = trim($_POST['action'] ?? '');
csrf_check();

// Ausnahmslos Admin-Session nötig. Der frühere Self-Service-Passwort-Reset
// (ohne Login möglich) wurde nach api/panel_pw_reset.php ausgelagert, damit
// diese Datei komplett auf VPN/Büro-IP beschränkt werden kann.
if (empty($_SESSION['panel_user']) || !panel_hat_rolle('admin')) {
    json_err('Zugriff verweigert.', 403);
}

switch ($action) {

    // ── TKs laden (mit Airline) ───────────────────────────────────
    case 'list_tks':
        $rows = db()->query(
            "SELECT t.id, t.kuerzel, t.bezeichnung, t.airline, t.aktiv,
                    COUNT(DISTINCT r.id) AS ref_count,
                    COUNT(DISTINCT m.id) AS mitglied_count
             FROM tks t
             LEFT JOIN tarifreferenten r ON r.tk_id = t.id AND r.aktiv = 1
             LEFT JOIN mitglieder m      ON m.tk_id = t.id AND m.aktiv = 1
             GROUP BY t.id
             ORDER BY t.kuerzel"
        )->fetchAll();
        json_ok($rows);
        break;

    // ── TK anlegen (mit optionaler Airline + Provisioning) ───────
    case 'create_tk':
        $kuerzel     = clean($_POST['kuerzel']     ?? '');
        $bezeichnung = clean($_POST['bezeichnung'] ?? '');
        $airline     = clean($_POST['airline']     ?? '');
        if (!$kuerzel || !$bezeichnung) {
            json_err('Kürzel und Bezeichnung sind Pflichtfelder.');
        }
        try {
            db()->prepare(
                "INSERT INTO tks (kuerzel, bezeichnung, airline, aktiv) VALUES (?,?,?,1)"
            )->execute([$kuerzel, $bezeichnung, $airline ?: null]);
            $new_id = (int)db()->lastInsertId();

            // DB-Views für diese TK anlegen
            tk_provision_create($new_id, $kuerzel);

            audit('tk_erstellt', $new_id, "Kürzel:{$kuerzel}");
            json_ok(['id' => $new_id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') json_err('Dieses Kürzel existiert bereits.');
            throw $e;
        }
        break;

    // ── TK Aktiv/Inaktiv ─────────────────────────────────────────
    case 'toggle_tk':
        $id = (int)($_POST['tk_id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        db()->prepare("UPDATE tks SET aktiv = NOT aktiv WHERE id=?")->execute([$id]);
        json_ok();
        break;

    // ── TK löschen ───────────────────────────────────────────────
    case 'delete_tk':
        $id = (int)($_POST['tk_id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $offen = db()->prepare(
            "SELECT COUNT(*) FROM antraege WHERE tk_id = ? AND status = 'ausstehend'"
        );
        $offen->execute([$id]);
        if ((int)$offen->fetchColumn() > 0) {
            json_err('TK kann nicht gelöscht werden – es gibt noch ausstehende Anträge.');
        }

        $mitglieder_offen = db()->prepare(
            "SELECT COUNT(*) FROM mitglieder WHERE tk_id = ? AND aktiv = 1"
        );
        $mitglieder_offen->execute([$id]);
        if ((int)$mitglieder_offen->fetchColumn() > 0) {
            json_err('TK kann nicht gelöscht werden – es sind noch aktive Mitglieder vorhanden. Bitte zuerst deaktivieren.');
        }

        // TK-Kürzel für Provisioning holen (vor dem Löschen)
        $tk_row = db()->prepare("SELECT kuerzel FROM tks WHERE id = ? LIMIT 1");
        $tk_row->execute([$id]);
        $tk_data = $tk_row->fetch();

        db()->prepare("UPDATE antraege SET tk_id = NULL WHERE tk_id = ?")->execute([$id]);
        db()->prepare("UPDATE mitglieder SET aktiv = 0 WHERE tk_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM budgets WHERE tk_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM tarifreferenten WHERE tk_id = ?")->execute([$id]);
        db()->prepare("DELETE FROM tks WHERE id = ?")->execute([$id]);

        // DB-Views entfernen
        if ($tk_data) {
            tk_provision_drop($id, $tk_data['kuerzel']);
        }

        audit('tk_geloescht', null, "TK-ID: {$id}");
        json_ok();
        break;

    // ── Referenten-Liste ─────────────────────────────────────────
    case 'list_referenten':
        $refs = db()->query(
            "SELECT r.*, t.kuerzel FROM tarifreferenten r JOIN tks t ON t.id=r.tk_id ORDER BY t.kuerzel, r.name"
        )->fetchAll();
        json_ok($refs);
        break;

    // ── Referent anlegen ─────────────────────────────────────────
    case 'create_referent':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        $name  = clean($_POST['name']  ?? '');
        $email = clean_email($_POST['email'] ?? '');
        if (!$tk_id || !$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_err('Ungültige Eingabe.');
        }
        db()->prepare(
            "INSERT INTO tarifreferenten (tk_id, name, email) VALUES (?,?,?)"
        )->execute([$tk_id, $name, $email]);
        audit('referent_erstellt', null, "{$name} / {$email}");
        json_ok();
        break;

    // ── Referent löschen ─────────────────────────────────────────
    case 'delete_referent':
        $id = (int)($_POST['ref_id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        db()->prepare("DELETE FROM tarifreferenten WHERE id=?")->execute([$id]);
        json_ok();
        break;

    // ══════════════════════════════════════════════════════════════
    // MITGLIEDER-VERWALTUNG
    // ══════════════════════════════════════════════════════════════

    // ── Mitglieder-Liste (einer TK) ──────────────────────────────
    case 'list_mitglieder':
        $tk_id = (int)($_POST['tk_id'] ?? 0);
        if (!$tk_id) json_err('TK-ID fehlt.');

        $stmt = db()->prepare(
            "SELECT id, tk_id, vorname, aktiv,
                    (einladung_token IS NOT NULL) AS einladung_ausstehend,
                    erstellt_am, erstellt_von, letzter_login
             FROM mitglieder
             WHERE tk_id = ?
             ORDER BY vorname"
        );
        $stmt->execute([$tk_id]);
        $rows = $stmt->fetchAll();

        // Nachname entschlüsseln, E-Mail entschlüsseln
        foreach ($rows as &$r) {
            $r['aktiv']                 = (bool)$r['aktiv'];
            $r['einladung_ausstehend']  = (bool)$r['einladung_ausstehend'];
        }
        json_ok($rows);
        break;

    // ── Mitglied-Detail (mit E-Mail für Admin) ───────────────────
    case 'get_mitglied':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $stmt = db()->prepare(
            "SELECT m.*, t.bezeichnung AS tk_bezeichnung
             FROM mitglieder m JOIN tks t ON t.id = m.tk_id
             WHERE m.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_err('Nicht gefunden.', 404);

        $m['nachname'] = decrypt($m['nachname_enc']);
        $m['email']    = decrypt($m['vc_email_enc']);
        unset($m['nachname_enc'], $m['vc_email_enc'], $m['password_hash'],
              $m['einladung_token'], $m['pw_reset_token']);
        json_ok($m);
        break;

    // ── Mitglied anlegen & Einladungsmail versenden ───────────────
    case 'create_mitglied':
        $tk_id    = (int)($_POST['tk_id']    ?? 0);
        $vorname  = clean($_POST['vorname']  ?? '');
        $nachname = clean($_POST['nachname'] ?? '');
        $email    = clean_email($_POST['email'] ?? '');

        if (!$tk_id)    json_err('TK-ID fehlt.');
        if (!$vorname)  json_err('Vorname ist ein Pflichtfeld.');
        if (!$nachname) json_err('Nachname ist ein Pflichtfeld.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_err('Ungültige E-Mail-Adresse.');
        }

        // TK prüfen
        $tk_stmt = db()->prepare("SELECT id, bezeichnung FROM tks WHERE id = ? AND aktiv = 1 LIMIT 1");
        $tk_stmt->execute([$tk_id]);
        $tk = $tk_stmt->fetch();
        if (!$tk) json_err('Ungültige oder inaktive TK.');

        // Doppelt-Prüfung (gleiche E-Mail in dieser TK)
        $dup = db()->prepare(
            "SELECT id FROM mitglieder WHERE email_hash = ? LIMIT 1"
        );
        $dup->execute([email_hash($email)]);
        if ($dup->fetch()) {
            json_err('Diese E-Mail-Adresse ist bereits als Mitglied registriert.');
        }

        try {
            $new_id = mitglied_anlegen(
                $tk_id, $vorname, $nachname, $email,
                $_SESSION['panel_user']
            );

            // Einladungs-Token für Mail holen
            $tok_stmt = db()->prepare(
                "SELECT einladung_token FROM mitglieder WHERE id = ? LIMIT 1"
            );
            $tok_stmt->execute([$new_id]);
            $tok = $tok_stmt->fetchColumn();

            mail_einladung_mitglied($email, $vorname, $nachname, $tk['bezeichnung'], $tok);

            audit('mitglied_angelegt', $new_id,
                "TK:{$tk_id} {$vorname} {$nachname} <{$email}> durch {$_SESSION['panel_user']}");

            json_ok(['id' => $new_id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_err('Diese E-Mail-Adresse ist bereits registriert.');
            }
            throw $e;
        }
        break;

    // ── Einladung erneut versenden ────────────────────────────────
    case 'resend_einladung':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $stmt = db()->prepare(
            "SELECT m.*, t.bezeichnung AS tk_bezeichnung
             FROM mitglieder m JOIN tks t ON t.id = m.tk_id
             WHERE m.id = ? AND m.aktiv = 1 LIMIT 1"
        );
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        if (!$m) json_err('Mitglied nicht gefunden.');
        if ($m['einladung_token'] === null) {
            json_err('Dieses Mitglied hat die Einladung bereits angenommen.');
        }

        // Neuen Token + verlängerte Gültigkeit
        $new_token = bin2hex(random_bytes(32));
        $new_ablauf = date('Y-m-d H:i:s', time() + MK_EINLADUNG_TTL);
        db()->prepare(
            "UPDATE mitglieder SET einladung_token = ?, einladung_ablauf = ? WHERE id = ?"
        )->execute([$new_token, $new_ablauf, $id]);

        $email    = decrypt($m['vc_email_enc']);
        $nachname = decrypt($m['nachname_enc']);
        mail_einladung_mitglied($email, $m['vorname'], $nachname, $m['tk_bezeichnung'], $new_token);

        audit('einladung_erneut', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Mitglied bearbeiten (Vorname/Nachname/E-Mail) ─────────────
    case 'update_mitglied':
        $id       = (int)($_POST['id']       ?? 0);
        $vorname  = clean($_POST['vorname']  ?? '');
        $nachname = clean($_POST['nachname'] ?? '');
        $email    = clean_email($_POST['email'] ?? '');

        if (!$id || !$vorname || !$nachname) json_err('Pflichtfelder fehlen.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail.');

        $new_hash   = email_hash($email);
        $nachname_e = encrypt($nachname);
        $email_e    = encrypt($email);

        // Prüfen ob E-Mail bereits von anderem Mitglied genutzt
        $dup = db()->prepare(
            "SELECT id FROM mitglieder WHERE email_hash = ? AND id != ? LIMIT 1"
        );
        $dup->execute([$new_hash, $id]);
        if ($dup->fetch()) json_err('Diese E-Mail ist bereits einem anderen Mitglied zugeordnet.');

        db()->prepare(
            "UPDATE mitglieder
             SET vorname = ?, nachname_enc = ?, vc_email_enc = ?, email_hash = ?
             WHERE id = ?"
        )->execute([$vorname, $nachname_e, $email_e, $new_hash, $id]);

        audit('mitglied_bearbeitet', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Mitglied aktivieren / deaktivieren ───────────────────────
    case 'toggle_mitglied':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        db()->prepare("UPDATE mitglieder SET aktiv = NOT aktiv WHERE id = ?")->execute([$id]);

        // Bei Deaktivierung alle Sessions löschen
        $neu_status = db()->prepare("SELECT aktiv FROM mitglieder WHERE id = ? LIMIT 1");
        $neu_status->execute([$id]);
        if (!(int)$neu_status->fetchColumn()) {
            db()->prepare(
                "DELETE FROM mitglieder_sessions WHERE mitglied_id = ?"
            )->execute([$id]);
        }

        audit('mitglied_toggle', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Mitglied löschen ─────────────────────────────────────────
    case 'delete_mitglied':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        // Anträge des Mitglieds entknüpfen (nicht löschen)
        db()->prepare(
            "UPDATE antraege SET mitglied_id = NULL WHERE mitglied_id = ?"
        )->execute([$id]);

        db()->prepare(
            "DELETE FROM mitglieder_sessions WHERE mitglied_id = ?"
        )->execute([$id]);
        db()->prepare(
            "DELETE FROM mitglieder WHERE id = ?"
        )->execute([$id]);

        audit('mitglied_geloescht', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── Provisioning: Views für alle TKs neu anlegen ─────────────
    // (Admin-Notfall-Funktion, z.B. nach DB-Restore)
    case 'reprovision_all':
        tk_provision_all();
        audit('reprovision_all', null, "Durch {$_SESSION['panel_user']}");
        json_ok(['message' => 'Views für alle TKs neu angelegt.']);
        break;

    // ── Benutzer-Liste ───────────────────────────────────────────
    case 'list_users':
        $users = db()->query(
            "SELECT id, username, email, rolle, aktiv, erstellt_am, letzter_login FROM panel_users ORDER BY rolle, username"
        )->fetchAll();
        json_ok($users);
        break;

    // ── Benutzer anlegen / E-Mail & Rolle aktualisieren ──────────
    case 'save_user':
        $username = clean($_POST['username'] ?? '');
        $email    = clean($_POST['email']    ?? '');
        // Mehrfachrollen: kommagetrennte Liste (z.B. "buero,finance").
        // Jede einzelne Rolle gegen die erlaubte Menge validieren, Duplikate
        // entfernen; ohne gültige Rolle wird auf "buero" zurückgefallen,
        // damit nie ein Account ganz ohne Berechtigung entsteht.
        $erlaubte_rollen = ['admin', 'buero', 'finance'];
        $rollen_liste = array_values(array_unique(array_intersect(
            array_map('trim', explode(',', $_POST['rolle'] ?? '')),
            $erlaubte_rollen
        )));
        $rolle = $rollen_liste ? implode(',', $rollen_liste) : 'buero';
        if (strlen($username) < 3) json_err('Benutzername mindestens 3 Zeichen.');
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('Ungültige E-Mail-Adresse.');

        $existing = db()->prepare("SELECT id FROM panel_users WHERE username = ? LIMIT 1");
        $existing->execute([$username]);
        $ex = $existing->fetch();

        try {
            if ($ex) {
                // Vorhandenen Benutzer: nur E-Mail und Rolle aktualisieren
                db()->prepare(
                    "UPDATE panel_users SET email = ?, rolle = ? WHERE id = ?"
                )->execute([$email ?: null, $rolle, $ex['id']]);
                audit('user_aktualisiert', $ex['id'], "Benutzer: {$username}, Rolle: {$rolle}");
                json_ok(['message' => 'Benutzer aktualisiert.']);
            } else {
                // Neuen Benutzer anlegen – kein Passwort, wird per Einladung gesetzt
                db()->prepare(
                    "INSERT INTO panel_users (username, password_hash, rolle, email, aktiv) VALUES (?,?,?,?,1)"
                )->execute([$username, '', $rolle, $email ?: null]);
                audit('user_angelegt', null, "Benutzer: {$username}, Rolle: {$rolle}");
                json_ok(['message' => 'Benutzer angelegt. Bitte Einladung per E-Mail versenden.']);
            }
        } catch (PDOException) {
            json_err('Fehler beim Speichern.');
        }
        break;

    // ── Einladungslink an Panel-Benutzer senden ───────────────────
    case 'send_panel_invite':
        $user_id = (int)($_POST['user_id'] ?? 0);
        if (!$user_id) json_err('Ungültige ID.');

        $stmt = db()->prepare(
            "SELECT id, username, email, aktiv FROM panel_users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();

        if (!$u)          json_err('Benutzer nicht gefunden.');
        if (!$u['aktiv']) json_err('Benutzer ist deaktiviert.');
        if (!$u['email']) json_err('Keine E-Mail-Adresse hinterlegt.');

        $token  = bin2hex(random_bytes(32));
        $ablauf = date('Y-m-d H:i:s', time() + (defined('PANEL_EINLADUNG_TTL') ? PANEL_EINLADUNG_TTL : 172800));

        db()->prepare(
            "UPDATE panel_users SET pw_reset_token = ?, pw_reset_ablauf = ? WHERE id = ?"
        )->execute([$token, $ablauf, $user_id]);

        mail_einladung_panel($u['email'], $u['username'], $token);
        audit('panel_einladung_versendet', $user_id, "Durch {$_SESSION['panel_user']}");
        json_ok(['message' => 'Einladung versendet.']);
        break;

    // ── Passwort-Reset-Link an Panel-Benutzer senden ──────────────
    case 'send_panel_pw_reset':
        $user_id = (int)($_POST['user_id'] ?? 0);
        if (!$user_id) json_err('Ungültige ID.');

        $stmt = db()->prepare(
            "SELECT id, username, email, aktiv FROM panel_users WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();

        if (!$u)          json_err('Benutzer nicht gefunden.');
        if (!$u['aktiv']) json_err('Benutzer ist deaktiviert.');
        if (!$u['email']) json_err('Keine E-Mail-Adresse hinterlegt.');

        $token  = bin2hex(random_bytes(32));
        $ablauf = date('Y-m-d H:i:s', time() + 3600);

        db()->prepare(
            "UPDATE panel_users SET pw_reset_token = ?, pw_reset_ablauf = ? WHERE id = ?"
        )->execute([$token, $ablauf, $user_id]);

        mail_pw_reset_panel($u['email'], $u['username'], $token);
        audit('panel_pw_reset_versendet', $user_id, "Durch {$_SESSION['panel_user']}");
        json_ok(['message' => 'Passwort-Reset-Link versendet.']);
        break;

    // ── Benutzer deaktivieren/aktivieren ─────────────────────────
    case 'toggle_user':
        $id = (int)($_POST['user_id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        $own = db()->prepare("SELECT id FROM panel_users WHERE username=? LIMIT 1");
        $own->execute([$_SESSION['panel_user']]);
        if ((int)$own->fetchColumn() === $id) json_err('Eigenen Account nicht deaktivierbar.');
        db()->prepare("UPDATE panel_users SET aktiv = NOT aktiv WHERE id=?")->execute([$id]);
        json_ok();
        break;

    // ── Benutzer löschen ─────────────────────────────────────────
    case 'delete_user':
        $id = (int)($_POST['user_id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        $own = db()->prepare("SELECT id FROM panel_users WHERE username = ? LIMIT 1");
        $own->execute([$_SESSION['panel_user']]);
        if ((int)$own->fetchColumn() === $id) json_err('Eigenen Account nicht löschbar.');
        db()->prepare("DELETE FROM panel_users WHERE id=?")->execute([$id]);
        audit('user_geloescht', null, "User-ID: {$id}");
        json_ok();
        break;

    // ── Storno-Log laden ─────────────────────────────────────────
    case 'list_storno_log':
        $where  = ['1=1'];
        $params = [];

        $filter_tk   = (int)($_POST['tk_id']        ?? 0);
        $filter_von  = clean($_POST['von']           ?? '');
        $filter_bis  = clean($_POST['bis']           ?? '');
        $filter_user = clean($_POST['storniert_von'] ?? '');

        if ($filter_tk)   { $where[] = 'sl.tk_id = ?';             $params[] = $filter_tk; }
        if ($filter_von)  { $where[] = 'sl.storniert_am >= ?';     $params[] = $filter_von . ' 00:00:00'; }
        if ($filter_bis)  { $where[] = 'sl.storniert_am <= ?';     $params[] = $filter_bis . ' 23:59:59'; }
        if ($filter_user) { $where[] = 'sl.storniert_von LIKE ?';  $params[] = '%' . $filter_user . '%'; }

        $sql = "
            SELECT
                sl.id, sl.storno_aktion_id, sl.ereignis_id,
                sl.storniert_von, sl.storniert_am, sl.grund,
                sl.betroffene_tage, sl.betroffene_antraege,
                sl.tk_id,
                COALESCE(sl.tk_name,      tk.bezeichnung)  AS tk_name,
                COALESCE(sl.veranstaltung, e.veranstaltung) AS veranstaltung,
                COALESCE(sl.ereignis_von,  e.zeitraum_von)  AS ereignis_von,
                COALESCE(sl.ereignis_bis,  e.zeitraum_bis)  AS ereignis_bis
            FROM storno_log sl
            LEFT JOIN ereignisse e  ON e.id  = sl.ereignis_id
            LEFT JOIN tks        tk ON tk.id = sl.tk_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY sl.storniert_am DESC, sl.storno_aktion_id, sl.betroffene_tage
            LIMIT 2000
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $aids    = json_decode($row['betroffene_antraege'] ?? '[]', true);
            $details = [];
            if (!empty($aids)) {
                $ph    = implode(',', array_fill(0, count($aids), '?'));
                $astmt = db()->prepare(
                    "SELECT a.id AS antrag_id, a.vorname, a.nachname_enc,
                            a.airline, a.position, t.kuerzel AS tk_kuerzel
                     FROM antraege a
                     LEFT JOIN tks t ON t.id = a.tk_id
                     WHERE a.id IN ({$ph})"
                );
                $astmt->execute($aids);
                foreach ($astmt->fetchAll() as $a) {
                    $a['nachname'] = decrypt($a['nachname_enc']);
                    unset($a['nachname_enc']);
                    $details[] = $a;
                }
            }
            $row['betroffene_antraege_details'] = $details;
            unset($row['betroffene_antraege']);
        }
        json_ok($rows);
        break;

    // ── Storno-Berechtigte: Liste ─────────────────────────────────
    case 'list_storno_berechtigte':
        $rows = db()->query(
            "SELECT sb.id, sb.username, sb.name, sb.email, sb.aktiv,
                    sb.alle_tks, sb.erstellt_am,
                    GROUP_CONCAT(sbt.tk_id ORDER BY sbt.tk_id) AS tk_ids_csv,
                    GROUP_CONCAT(t.kuerzel ORDER BY sbt.tk_id) AS tk_kuerzel_csv
             FROM storno_berechtigte sb
             LEFT JOIN storno_berechtigte_tks sbt ON sbt.berechtigter_id = sb.id
             LEFT JOIN tks t ON t.id = sbt.tk_id
             GROUP BY sb.id
             ORDER BY sb.name"
        )->fetchAll();

        foreach ($rows as &$r) {
            $r['aktiv']      = (bool)$r['aktiv'];
            $r['alle_tks']   = (bool)$r['alle_tks'];
            $r['tk_ids']     = $r['tk_ids_csv']
                ? array_map('intval', explode(',', $r['tk_ids_csv'])) : [];
            $r['tk_kuerzel'] = $r['tk_kuerzel_csv']
                ? explode(',', $r['tk_kuerzel_csv']) : [];
            unset($r['tk_ids_csv'], $r['tk_kuerzel_csv']);
        }
        json_ok($rows);
        break;

    // ── Storno-Berechtigter: Einzelabruf ─────────────────────────
    case 'get_storno_berechtigter':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        $stmt = db()->prepare(
            "SELECT sb.*, GROUP_CONCAT(sbt.tk_id) AS tk_ids_csv
             FROM storno_berechtigte sb
             LEFT JOIN storno_berechtigte_tks sbt ON sbt.berechtigter_id = sb.id
             WHERE sb.id = ?
             GROUP BY sb.id LIMIT 1"
        );
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) json_err('Nicht gefunden.', 404);

        $r['aktiv']    = (bool)$r['aktiv'];
        $r['alle_tks'] = (bool)$r['alle_tks'];
        $r['tk_ids']   = $r['tk_ids_csv']
            ? array_map('intval', explode(',', $r['tk_ids_csv'])) : [];
        unset($r['tk_ids_csv']);
        json_ok($r);
        break;

    // ── Storno-Berechtigter: Anlegen ─────────────────────────────
    case 'create_storno_berechtigter':
        $username = clean($_POST['username'] ?? '');
        $name     = clean($_POST['name']     ?? '');
        $email    = clean($_POST['email']    ?? '');
        $aktiv    = (int)($_POST['aktiv']    ?? 1);
        $alle_tks = (int)($_POST['alle_tks'] ?? 1);
        $tk_ids   = array_map('intval', $_POST['tk_ids'] ?? []);

        if (!$username || !$name) json_err('Benutzername und Name sind Pflichtfelder.');

        $chk = db()->prepare("SELECT id FROM panel_users WHERE username = ? LIMIT 1");
        $chk->execute([$username]);
        if (!$chk->fetch()) {
            json_err("Benutzername \"{$username}\" existiert nicht in den Panel-Benutzern.");
        }

        try {
            db()->prepare(
                "INSERT INTO storno_berechtigte
                 (username, name, email, aktiv, alle_tks, erstellt_von)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$username, $name, $email ?: null, $aktiv, $alle_tks,
                        $_SESSION['panel_user']]);

            $new_id = (int)db()->lastInsertId();
            audit('storno_berechtigung_angelegt', $new_id,
                "User:{$username} durch {$_SESSION['panel_user']}");
            json_ok(['id' => $new_id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_err("Dieser Benutzername ist bereits als Storno-Berechtigter eingetragen.");
            }
            throw $e;
        }
        break;

    // ── Storno-Berechtigter: Aktualisieren ───────────────────────
    case 'update_storno_berechtigter':
        $id       = (int)($_POST['id']       ?? 0);
        $name     = clean($_POST['name']     ?? '');
        $email    = clean($_POST['email']    ?? '');
        $aktiv    = (int)($_POST['aktiv']    ?? 1);
        $alle_tks = (int)($_POST['alle_tks'] ?? 1);
        $tk_ids   = array_map('intval', $_POST['tk_ids'] ?? []);

        if (!$id || !$name) json_err('Ungültige Parameter.');

        db()->prepare(
            "UPDATE storno_berechtigte
             SET name=?, email=?, aktiv=?, alle_tks=? WHERE id=?"
        )->execute([$name, $email ?: null, $aktiv, $alle_tks, $id]);

        if (!$alle_tks && !empty($tk_ids)) {
            $ins = db()->prepare(
                "INSERT IGNORE INTO storno_berechtigte_tks (berechtigter_id, tk_id) VALUES (?,?)"
            );
            foreach ($tk_ids as $tid) {
                if ($tid) $ins->execute([$id, $tid]);
            }
        }

        audit('storno_berechtigung_geaendert', $id, "Durch {$_SESSION['panel_user']}");
        json_ok(['id' => $id]);
        break;

    // ── Storno-Berechtigter: Löschen ─────────────────────────────
    case 'delete_storno_berechtigter':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');

        db()->prepare(
            "DELETE FROM storno_berechtigte_tks WHERE berechtigter_id = ?"
        )->execute([$id]);
        db()->prepare(
            "DELETE FROM storno_berechtigte WHERE id = ?"
        )->execute([$id]);

        audit('storno_berechtigung_geloescht', $id, "Durch {$_SESSION['panel_user']}");
        json_ok();
        break;

    // ── TK-Airlines: Liste ────────────────────────────────────────
    case 'list_tk_airlines':
        $rows = db()->query(
            "SELECT ta.id, ta.tk_id, ta.airline, t.kuerzel AS tk_kuerzel
             FROM tk_airlines ta
             JOIN tks t ON t.id = ta.tk_id
             ORDER BY t.kuerzel, ta.airline"
        )->fetchAll();
        json_ok($rows);
        break;

    // ── TK-Airlines: Anlegen ─────────────────────────────────────
    case 'create_tk_airline':
        $tk_id   = (int)($_POST['tk_id']  ?? 0);
        $airline = clean($_POST['airline'] ?? '');
        if (!$tk_id || !$airline) json_err('TK und Airline sind Pflichtfelder.');

        $chk = db()->prepare("SELECT id FROM tks WHERE id = ? LIMIT 1");
        $chk->execute([$tk_id]);
        if (!$chk->fetch()) json_err('Ungültige TK.');

        try {
            db()->prepare(
                "INSERT INTO tk_airlines (tk_id, airline) VALUES (?, ?)"
            )->execute([$tk_id, $airline]);
            json_ok(['id' => (int)db()->lastInsertId()]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                json_err("Diese Airline ist dieser TK bereits zugeordnet.");
            }
            throw $e;
        }
        break;

    // ── TK-Airlines: Löschen ─────────────────────────────────────
    case 'delete_tk_airline':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_err('ID fehlt.');
        db()->prepare("DELETE FROM tk_airlines WHERE id = ?")->execute([$id]);
        json_ok();
        break;

    // ── Eigenes Passwort ändern (Admin/Büro, eingeloggt) ─────────
    case 'change_own_password':
        $alt_pw  = $_POST['alt_passwort']  ?? '';
        $neu_pw  = $_POST['neu_passwort']  ?? '';
        $neu_pw2 = $_POST['neu_passwort2'] ?? '';
        if ($neu_pw !== $neu_pw2)     json_err('Die neuen Passwörter stimmen nicht überein.');
        if ($fehler = password_ist_sicher($neu_pw, [$_SESSION['panel_user']])) {
            json_err($fehler);
        }
        $stmt = db()->prepare("SELECT id, password_hash FROM panel_users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['panel_user']]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($alt_pw, $u['password_hash'])) {
            json_err('Das aktuelle Passwort ist falsch.');
        }
        $hash = password_hash($neu_pw, PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare("UPDATE panel_users SET password_hash = ? WHERE id = ?")->execute([$hash, $u['id']]);
        audit('passwort_geaendert', null, "Benutzer: {$_SESSION['panel_user']}");
        json_ok();
        break;

    // Hinweis: request_pw_reset_panel / do_pw_reset_panel wurden nach
    // api/panel_pw_reset.php ausgelagert (öffentlicher Self-Service-Reset,
    // ohne Admin-Session). Diese Datei hier verlangt jetzt ausnahmslos Admin.
    //
    // Hinweis: get_config / save_config wurden vollständig entfernt. Die
    // Server-Konfiguration (DB-, SMTP-Zugangsdaten etc.) wird seit der
    // Umstellung auf Umgebungsvariablen ausschließlich direkt im Azure-Portal
    // unter App Service → Einstellungen → Umgebungsvariablen gepflegt, nicht
    // mehr über das Admin-Panel.
    //
    // Mail-Einstellungen (MAIL_FROM, MAIL_FROM_NAME, MAIL_REPLY_TO) werden
    // in der Tabelle `app_settings` gespeichert und überschreiben zur Laufzeit
    // die Konstanten aus config.php (sofern gesetzt).

    // ── Mail-Einstellungen: Laden ─────────────────────────────────
    case 'get_mail_settings':
        $stmt = db()->query(
            "SELECT setting_key, setting_value FROM app_settings
              WHERE setting_key IN ('mail_from','mail_from_name','mail_reply_to')"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fallback-Werte: Umgebungsvariable, dann Hardcode
        $fb_name  = getenv('MAIL_FROM_NAME') ?: 'Freistellungssystem Tarif';
        $fb_from  = getenv('MAIL_FROM')      ?: 'granvogl@vcockpit.de';
        $fb_reply = getenv('MAIL_REPLY_TO')  ?: 'granvogl@vcockpit.de';

        // Tatsächlich aktiver Wert: DB-Wert (wenn gesetzt) > Umgebungsvariable > Fallback
        $aktiv_name  = ($rows['mail_from_name'] ?? '') ?: $fb_name;
        $aktiv_from  = ($rows['mail_from']      ?? '') ?: $fb_from;
        $aktiv_reply = ($rows['mail_reply_to']  ?? '') ?: $fb_reply;

        json_ok([
            'mail_from'      => $rows['mail_from']      ?? '',
            'mail_from_name' => $rows['mail_from_name'] ?? '',
            'mail_reply_to'  => $rows['mail_reply_to']  ?? '',
            'aktiv_werte'    => [
                'mail_from_name'    => $aktiv_name,
                'mail_from'         => $aktiv_from,
                'mail_reply_to'     => $aktiv_reply,
                'fallback_from_name'=> $fb_name,
                'fallback_from'     => $fb_from,
                'fallback_reply_to' => $fb_reply,
            ],
        ]);
        break;

    // ── Mail-Einstellungen: Speichern ─────────────────────────────
    case 'save_mail_settings':
        $from_name = trim($_POST['mail_from_name'] ?? '');
        $from      = trim($_POST['mail_from']      ?? '');
        $reply_to  = trim($_POST['mail_reply_to']  ?? '');

        // Einfache E-Mail-Validierung für nicht-leere Felder
        foreach (['Absender-Adresse' => $from, 'Antwort-Adresse' => $reply_to] as $label => $addr) {
            if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                json_err("$label ist keine gültige E-Mail-Adresse.");
            }
        }

        $upsert = db()->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
                  VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        foreach ([
            'mail_from_name' => $from_name,
            'mail_from'      => $from,
            'mail_reply_to'  => $reply_to,
        ] as $key => $val) {
            $upsert->execute([':k' => $key, ':v' => $val]);
        }

        audit('mail_settings_geaendert', null,
            "from_name={$from_name}; from={$from}; reply_to={$reply_to}; "
            . "admin={$_SESSION['panel_user']}"
        );
        json_ok(['message' => 'Mail-Einstellungen gespeichert.']);
        break;

    // ── Airline-Liste für Dropdowns (aus Mitglieder-Tabelle = korrekte Werte) ──
    case 'list_finance_airlines':
        $fa_rows = db()->query(
            "SELECT DISTINCT airline FROM (
                SELECT airline FROM tks               WHERE aktiv = 1 AND airline IS NOT NULL AND airline != ''
                UNION
                SELECT airline FROM mitglieder        WHERE airline IS NOT NULL AND airline != ''
                UNION
                SELECT airline FROM antraege          WHERE airline IS NOT NULL AND airline != ''
                UNION
                SELECT airline FROM airline_einstellungen WHERE airline IS NOT NULL AND airline != ''
                UNION
                SELECT airline FROM flugbetrieb_kontakte WHERE aktiv = 1 AND airline IS NOT NULL AND airline != ''
             ) x ORDER BY airline"
        )->fetchAll(PDO::FETCH_COLUMN);
        json_ok(['airlines' => $fa_rows]);
        break;

    // ── Airline-Veranstaltungen: Weiterleitung an buero.php-Logik ──
    // Das Admin-Panel ruft diese Actions über api() → admin.php auf.
    // Die eigentliche Implementierung liegt in buero.php (wiederverwendet).
    case 'list_airline_veranstaltungen':
    case 'save_airline_veranstaltung':
    case 'get_veranstaltungen_fuer_airline':
        // bootstrap.php bereits oben geladen – db(), clean(), audit() verfügbar
        if ($action === 'list_airline_veranstaltungen') {
            $rows = db()->query(
                "SELECT id, airline, veranstaltung, label, uw_key, sort_order, aktiv
                   FROM airline_veranstaltungen ORDER BY airline, sort_order, label"
            )->fetchAll();
            json_ok(['eintraege' => $rows]);
        } elseif ($action === 'get_veranstaltungen_fuer_airline') {
            $gvfa_airline = clean($_POST['airline'] ?? '');
            if (!$gvfa_airline) json_err('Airline fehlt.');
            $st = db()->prepare("SELECT veranstaltung, label, uw_key, sort_order FROM airline_veranstaltungen WHERE airline = ? AND aktiv = 1 ORDER BY sort_order, label");
            $st->execute([$gvfa_airline]);
            json_ok(['zusatz_typen' => $st->fetchAll()]);
        } else {
            // save_airline_veranstaltung
            $sav_id      = (int)($_POST['id']       ?? 0);
            $sav_airline = clean($_POST['airline']  ?? '');
            $sav_label   = clean($_POST['label']    ?? '');
            $sav_sort    = max(0, (int)($_POST['sort_order'] ?? 10));
            $sav_aktiv   = (int)($_POST['aktiv']   ?? 1) ? 1 : 0;
            $sav_delete  = (int)($_POST['delete']  ?? 0);
            if ($sav_delete && $sav_id) {
                db()->prepare("DELETE FROM airline_veranstaltungen WHERE id = ?")->execute([$sav_id]);
                audit('airline_veranstaltung_geloescht', null, "ID:{$sav_id} durch {$_SESSION['panel_user']}");
                json_ok();
            }
            if (!$sav_airline) json_err('Airline fehlt.');
            if (!$sav_label)   json_err('Bezeichnung fehlt.');
            $sav_veranstaltung = $sav_label;
            $sav_uw_key        = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $sav_label));
            if ($sav_id) {
                db()->prepare("UPDATE airline_veranstaltungen SET airline=?, veranstaltung=?, label=?, uw_key=?, sort_order=?, aktiv=? WHERE id=?")
                    ->execute([$sav_airline, $sav_veranstaltung, $sav_label, $sav_uw_key, $sav_sort, $sav_aktiv, $sav_id]);
            } else {
                db()->prepare("INSERT INTO airline_veranstaltungen (airline, veranstaltung, label, uw_key, sort_order, aktiv) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label), uw_key=VALUES(uw_key), sort_order=VALUES(sort_order), aktiv=VALUES(aktiv)")
                    ->execute([$sav_airline, $sav_veranstaltung, $sav_label, $sav_uw_key, $sav_sort, $sav_aktiv]);
            }
            audit('airline_veranstaltung_gespeichert', null, "Airline:{$sav_airline} Label:{$sav_label} durch {$_SESSION['panel_user']}");
            json_ok();
        }
        break;

    default:
        json_err('Unbekannte Aktion.', 400);
}
