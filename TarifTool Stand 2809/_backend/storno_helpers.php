<?php
// ============================================================
// _backend/storno_helpers.php  –  gemeinsame Hilfsfunktionen
// für Storno-E-Mails und system_einstellungen.
// Verwendet von: api/buero.php, api/antrag_storno.php
// ============================================================

if (!function_exists('build_storno_email_subject')) {
/**
 * Baut den E-Mail-Betreff:
 * "Bitte um V4-Storno [Namen / Anzahl] für [Daten]"
 */
function build_storno_email_subject(array $mitglieder, array $tage_iso): string
{
    $tage_fmt = array_map(function ($t) {
        [$y, $m, $d] = explode('-', $t);
        return "$d.$m.$y";
    }, $tage_iso);
    sort($tage_fmt);

    $namen = (count($mitglieder) <= 3)
        ? implode(', ', array_map(
            fn($m) => trim($m['vorname'] . ' ' . $m['nachname']),
            $mitglieder))
        : count($mitglieder) . ' Mitglieder';

    return 'Bitte um V4-Storno ' . $namen . ' für ' . implode(', ', $tage_fmt);
}
}

if (!function_exists('build_storno_email_body')) {
/**
 * Baut den E-Mail-Body mit Symbol, Stornodaten und Mitgliederliste.
 * $codes = Freistellungscodes/-symbole aus antraege.freistellungscode
 */
function build_storno_email_body(
    array  $mitglieder,
    array  $tage_iso,
    string $bearbeiter_name,
    array  $codes = []
): string {
    $tage_fmt = array_map(function ($t) {
        [$y, $m, $d] = explode('-', $t);
        return "$d.$m.$y";
    }, $tage_iso);
    sort($tage_fmt);
    $tage_str = implode(', ', $tage_fmt);

    $symbol_zeile = !empty($codes)
        ? 'Symbol:            ' . implode(', ', $codes) . "
"
        : '';

    $namen = array_map(
        fn($m) => '– ' . trim($m['vorname'] . ' ' . $m['nachname']),
        $mitglieder
    );

    if (count($mitglieder) === 1) {
        $mitglied_block = 'Mitglied:          ' . ltrim($namen[0], '– ');
    } else {
        $mitglied_block = 'Betroffene Mitglieder:' . "
" . implode("
", $namen);
    }

    return "Sehr geehrte Damen und Herren,

"
        . "mit der Bitte um Austrag des entsprechenden Symbols. Vielen Dank!

"
        . $symbol_zeile
        . 'Stornodatum/e:     ' . $tage_str . "
"
        . $mitglied_block . "

"
        . "Mit freundlichen Grüßen

"
        . $bearbeiter_name;
}
}

if (!function_exists('get_einstellung')) {
/** Liest einen Wert aus system_einstellungen. */
function get_einstellung(PDO $pdo, string $schluessel, string $default = ''): string
{
    // ORDER BY geaendert_am DESC statt einem einfachen WHERE: falls der
    // Tabelle (durch fehlenden Unique-Key auf "schluessel") mehrere Zeilen
    // mit demselben Schlüssel existieren, wird garantiert die zuletzt
    // gespeicherte gelesen, nicht irgendeine ältere/zufällige.
    $st = $pdo->prepare(
        'SELECT wert FROM system_einstellungen WHERE schluessel = ? ORDER BY geaendert_am DESC LIMIT 1'
    );
    $st->execute([$schluessel]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return ($row !== false && $row['wert'] !== null) ? $row['wert'] : $default;
}
}

if (!function_exists('set_einstellung')) {
/** Schreibt/aktualisiert einen Wert in system_einstellungen. */
function set_einstellung(PDO $pdo, string $schluessel, string $wert, string $user): void
{
    $pdo->prepare(
        'INSERT INTO system_einstellungen (schluessel, wert, geaendert_am, geaendert_von)
         VALUES (?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE
           wert          = VALUES(wert),
           geaendert_am  = NOW(),
           geaendert_von = VALUES(geaendert_von)'
    )->execute([$schluessel, $wert, $user]);
}
}
