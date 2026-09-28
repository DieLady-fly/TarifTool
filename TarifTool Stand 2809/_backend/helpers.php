<?php
// ============================================================
// _backend/helpers.php  –  Hilfsfunktionen
// ============================================================

// ── Eingabe ───────────────────────────────────────────────────
function clean(string $v): string {
    return htmlspecialchars(trim($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function clean_email(string $v): string {
    return filter_var(trim($v), FILTER_SANITIZE_EMAIL);
}

// ── Passwort-Policy (gilt für Mitglieder UND Panel-Benutzer) ──
// Gibt bei Verstoß eine Fehlermeldung zurück, sonst null (= Passwort ok).
// $verbotene_werte: optionale Liste (z.B. Benutzername, E-Mail), die das
// Passwort nicht sein darf - wo immer diese Werte an der Aufrufstelle
// bereits vorliegen, bitte übergeben; kostet sonst nichts extra.
function password_ist_sicher(string $pw, array $verbotene_werte = []): ?string {
    if (strlen($pw) < 12) {
        return 'Das Passwort muss mindestens 12 Zeichen lang sein.';
    }
    if (strlen($pw) > 200) {
        // Schutz gegen absichtlich extrem lange Eingaben (DoS auf bcrypt),
        // kein realistisches Passwort ist länger.
        return 'Das Passwort ist zu lang (maximal 200 Zeichen).';
    }

    $klassen = 0;
    if (preg_match('/[a-z]/', $pw)) $klassen++;
    if (preg_match('/[A-Z]/', $pw)) $klassen++;
    if (preg_match('/[0-9]/', $pw)) $klassen++;
    if (preg_match('/[^a-zA-Z0-9]/', $pw)) $klassen++;
    if ($klassen < 3) {
        return 'Das Passwort muss mindestens 3 der folgenden 4 Arten enthalten: ' .
               'Kleinbuchstaben, Großbuchstaben, Ziffern, Sonderzeichen.';
    }

    $pw_lower = mb_strtolower($pw);
    foreach ($verbotene_werte as $wert) {
        $wert = mb_strtolower(trim((string)$wert));
        if ($wert !== '' && ($wert === $pw_lower || str_contains($pw_lower, $wert))) {
            return 'Das Passwort darf nicht den Benutzernamen oder die E-Mail-Adresse enthalten.';
        }
    }

    // Kleine Sperrliste besonders häufiger/trivialer Passwörter. Kein
    // Ersatz für einen echten Leak-Abgleich (z.B. Have I Been Pwned),
    // aber fängt die naheliegendsten, häufigsten Fälle ohne externe
    // API-Abhängigkeit ab.
    static $haeufige_passwoerter = [
        'password','password1','password123','passwort','passwort1','passwort123',
        '123456789','1234567890','12345678901','qwertz123','qwertzuiop','qwertz1234',
        'asdfghjkl','iloveyou1','letmein123','welcome123','admin1234','admin12345',
        'buerobuero','tarifsystem','freistellung','wolke7wolke7',
    ];
    if (in_array($pw_lower, $haeufige_passwoerter, true)) {
        return 'Dieses Passwort ist zu weit verbreitet und daher unsicher. Bitte ein anderes wählen.';
    }

    return null;
}

// ── Security-Header (einmalig pro Request) ───────────────────
function send_security_headers(): void {
    static $sent = false;
    if ($sent) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $sent = true;
}

// ── Rate Limiting ─────────────────────────────────────────────
function rate_limit_ok(string $action = 'antrag'): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE ip = ? AND aktion = ? AND erstellt_am > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->execute([$ip, $action]);
    return (int)$stmt->fetchColumn() < RATE_LIMIT_PER_HOUR;
}

// ── Audit-Log ─────────────────────────────────────────────────
function audit(string $aktion, ?int $antrag_id = null, ?string $details = null): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    try {
        db()->prepare(
            "INSERT INTO audit_log (antrag_id, aktion, details, ip) VALUES (?, ?, ?, ?)"
        )->execute([$antrag_id, $aktion, $details, $ip]);
    } catch (Throwable) { /* Audit darf nie die Hauptanwendung crashen */ }
}

// ── Verbrauchte (endgültig genehmigte) Freistellungstage ─────
// "Genehmigt"-Bucket: zählt Tage mit status IN ('genehmigt','storno_buero').
// Storno Büro zählt bewusst mit dazu (der Tag war/ist genehmigt, auch
// wenn er nachträglich vom Büro storniert wurde). NICHT gezählt werden
// abgelehnt, abgelehnt_ag und storno_bestaetigt_ag.
// $tk_id: optional - null = über alle TKs dieser Airline hinweg zählen.
// $freistellungscode: optional - wenn gesetzt (z.B. 'FS'), nur Tage mit
// diesem Code zählen; leer/null = alle Codes zusammen.
function verbrauchte_tage(?int $tk_id, string $airline, int $jahr, ?string $freistellungscode = null): float {
    $sql = "SELECT COUNT(at2.id)
         FROM antrag_tage at2
         JOIN antraege a ON a.id = at2.antrag_id
         WHERE a.airline  = ?
           AND at2.status IN ('genehmigt','storno_buero')
           AND YEAR(at2.tag) = ?";
    $params = [$airline, $jahr];
    if ($tk_id !== null) {
        $sql .= " AND a.tk_id = ?";
        $params[] = $tk_id;
    }
    if ($freistellungscode !== null && $freistellungscode !== '') {
        $sql .= " AND a.freistellungscode = ?";
        $params[] = $freistellungscode;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

// ── Beantragte, aber noch nicht endgültig genehmigte Tage ────
// "Beantragt"-Bucket: zählt Tage mit status IN ('ausstehend',
// 'freigabe_buero','beantragt_ag') - der Antrag ist eingegangen, aber
// die Freistellung ist (egal in welcher Phase) noch nicht endgültig
// genehmigt. Gleiche Parameter wie verbrauchte_tage().
function beantragte_tage(?int $tk_id, string $airline, int $jahr, ?string $freistellungscode = null): float {
    $sql = "SELECT COUNT(at2.id)
         FROM antrag_tage at2
         JOIN antraege a ON a.id = at2.antrag_id
         WHERE a.airline  = ?
           AND at2.status IN ('ausstehend','freigabe_buero','beantragt_ag')
           AND YEAR(at2.tag) = ?";
    $params = [$airline, $jahr];
    if ($tk_id !== null) {
        $sql .= " AND a.tk_id = ?";
        $params[] = $tk_id;
    }
    if ($freistellungscode !== null && $freistellungscode !== '') {
        $sql .= " AND a.freistellungscode = ?";
        $params[] = $freistellungscode;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

// ── Genehmigte Tage, aufgeschlüsselt nach Quartal ─────────────
// Wie verbrauchte_tage() (also inkl. storno_buero), aber nach
// Kalenderquartal (1-4) gruppiert. Rückgabe hat immer alle vier
// Schlüssel, auch wenn 0 Tage.
function verbrauchte_tage_je_quartal(?int $tk_id, string $airline, int $jahr, ?string $freistellungscode = null): array {
    $sql = "SELECT QUARTER(at2.tag) AS q, COUNT(at2.id) AS anzahl
         FROM antrag_tage at2
         JOIN antraege a ON a.id = at2.antrag_id
         WHERE a.airline  = ?
           AND at2.status IN ('genehmigt','storno_buero')
           AND YEAR(at2.tag) = ?";
    $params = [$airline, $jahr];
    if ($tk_id !== null) {
        $sql .= " AND a.tk_id = ?";
        $params[] = $tk_id;
    }
    if ($freistellungscode !== null && $freistellungscode !== '') {
        $sql .= " AND a.freistellungscode = ?";
        $params[] = $freistellungscode;
    }
    $sql .= " GROUP BY QUARTER(at2.tag)";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [quartal => anzahl]
    return [
        1 => (float)($rows[1] ?? 0),
        2 => (float)($rows[2] ?? 0),
        3 => (float)($rows[3] ?? 0),
        4 => (float)($rows[4] ?? 0),
    ];
}

// ── Budget-Kennzahlen für eine konkrete (TK, Code)-Kombination ────
// Liefert [budget_id, budget_tage, beantragt, verbrauch, quartale] für
// genau eine mögliche Zeile in `budgets` (oder Nullwerte, falls dafür
// kein Datensatz existiert). $tk_id=null = über alle TKs der Airline
// hinweg, $freistellungscode='' = über alle Codes hinweg.
function budget_kennzahlen(?int $tk_id, string $airline, string $freistellungscode, int $jahr): array {
    $sql = "SELECT id, budget_tage FROM budgets
            WHERE airline = ? AND freistellungscode = ? AND jahr = ?
              AND tk_id " . ($tk_id === null ? 'IS NULL' : '= ?') . "
            LIMIT 1";
    $params = [$airline, $freistellungscode, $jahr];
    if ($tk_id !== null) $params[] = $tk_id;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $budget_id = $row ? (int)$row['id'] : null;
    $budget    = $row ? (float)$row['budget_tage'] : 0.0;

    return [
        $budget_id,
        $budget,
        beantragte_tage($tk_id, $airline, $jahr, $freistellungscode),
        verbrauchte_tage($tk_id, $airline, $jahr, $freistellungscode),
        verbrauchte_tage_je_quartal($tk_id, $airline, $jahr, $freistellungscode),
    ];
}

// ── Baut eine fertige Ausgabezeile für die Budget-Übersicht ───────
function budget_zeile(?int $budget_id, ?int $tk_id, ?string $tk_kuerzel, string $airline,
                       string $freistellungscode, float $budget, float $beantragt,
                       float $verbrauch, array $quartale): array {
    return [
        'budget_id'         => $budget_id,
        'tk_id'             => $tk_id,
        'tk_kuerzel'        => $tk_kuerzel ?? '– (alle TKs)',
        'airline'           => $airline,
        'freistellungscode' => $freistellungscode,
        'budget_tage'       => $budget,
        'beantragt'         => $beantragt,
        'verbrauch'         => $verbrauch,
        'rest'              => $budget - $verbrauch,
        'pct'               => $budget > 0 ? min(100, round($verbrauch / $budget * 100)) : 0,
        'quartale'          => $quartale,
    ];
}

// ── Status-Bezeichnung/Badge ──────────────────────────────────
// WICHTIG: Dieselben Bezeichnungen/Farben wie in assets/status.js
// (JS-Gegenstück für Seiten, die Status clientseitig rendern) -
// bei Änderungen IMMER beide Stellen (und ag_doc_auswertung.php,
// erinnerung_helpers.php) gemeinsam anpassen, sonst entsteht
// wieder die frühere Uneinheitlichkeit.
const STATUS_MAP = [
    'ausstehend'          => ['Ausstehend',            '#856404', '#fff3cd'],
    'freigabe_buero'      => ['Freigabe Büro',         '#1d4ed8', '#dbeafe'],
    'beantragt_ag'        => ['Beantragt bei AG',      '#2851a3', '#e0ecff'],
    'genehmigt'           => ['Genehmigt',             '#1e7e34', '#e6f4ea'],
    'abgelehnt'           => ['Abgelehnt',              '#c0392b', '#fde8e8'],
    'abgelehnt_ag'        => ['Abgelehnt (AG)',        '#7d1a1a', '#fde8e8'],
    'storno_buero'        => ['Storno Büro',           '#5a4a00', '#f5f0e0'],
    'storno_bestaetigt_ag'=> ['Storno bestätigt (AG)', '#1e7e34', '#e6f4ea'],
];

function status_label(string $status): string {
    return STATUS_MAP[$status][0] ?? $status;
}

function status_badge(string $status): string {
    [$label, $color, $bg] = STATUS_MAP[$status] ?? [$status, '#333', '#eee'];
    return sprintf(
        '<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:%s;color:%s">%s</span>',
        $bg, $color, htmlspecialchars($label)
    );
}

// ── JSON-Antwort ──────────────────────────────────────────────
function json_ok(mixed $data = null): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function json_err(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
