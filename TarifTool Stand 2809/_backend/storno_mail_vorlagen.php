<?php
// ============================================================
// _backend/storno_mail_vorlagen.php
// Vom Büro anpassbare Mailvorlagen für die Arbeitgeber-Mails bei
// Storno UND Umwidmung (bisher nur bei Storno vorhanden, jetzt
// auf beide ausgeweitet - analog zum bestehenden Vorlagen-System
// für die Beantragung, siehe ag_antrag_helpers.php).
//
// WICHTIG - geschützter Platzhalter: {{MITGLIEDER}} darf beim
// Bearbeiten NICHT aus dem Text entfernt werden (das ist die
// Stelle, an der die Namensliste automatisch eingefügt wird).
// mail_vorlage_pflicht_platzhalter_pruefen() erzwingt das beim
// Speichern - sowohl hier als auch (siehe api/buero.php)
// rückwirkend bei den Beantragungs-Vorlagen (ag_antrag_helpers).
//
// Platzhalter: {{TAGE}} {{MITGLIEDER}} {{SYMBOL}} {{BEARBEITER}}
//              {{TK}} {{VERANSTALTUNG}} {{ZIELTYP}} (nur Umwidmung)
// ============================================================

if (!function_exists('mail_vorlage_pflicht_platzhalter_pruefen')) {
/**
 * Prüft, ob ein Pflicht-Platzhalter (z.B. "{{LISTE}}" oder "{{MITGLIEDER}}")
 * noch im Text enthalten ist. Gibt eine Fehlermeldung zurück, falls nicht -
 * NULL, wenn alles in Ordnung ist. Wird sowohl von den neuen Storno-/
 * Umwidmung-Vorlagen als auch rückwirkend von den Beantragungs-Vorlagen
 * (ag_antrag_helpers.php) beim Speichern aufgerufen.
 */
function mail_vorlage_pflicht_platzhalter_pruefen(string $body, string $platzhalter): ?string
{
    if (strpos($body, $platzhalter) === false) {
        return "Der Platzhalter {$platzhalter} darf im Text nicht entfernt werden " .
               "(an dieser Stelle wird automatisch die Namensliste eingefügt).";
    }
    return null;
}
}

if (!function_exists('storno_vorlage_typen')) {
/** Erlaubte Vorlagen-Typen mit Anzeigenamen. */
function storno_vorlage_typen(): array
{
    return [
        'storno'    => 'Storno',
        'umwidmung' => 'Umwidmung',
    ];
}
}

if (!function_exists('storno_default_vorlagen')) {
/** Werkseinstellungen beider Vorlagen (Text entspricht möglichst genau
 *  dem bisherigen fest programmierten Storno-Mailtext). */
function storno_default_vorlagen(): array
{
    return [
        'storno' => [
            'subject' => 'Bitte um Storno {{SYMBOL}} für {{TAGE}}',
            'body' =>
                "Sehr geehrte Damen und Herren,\n\n" .
                "mit der Bitte um Austrag des entsprechenden Symbols. Vielen Dank!\n\n" .
                "Symbol:            {{SYMBOL}}\n" .
                "Stornodatum/e:     {{TAGE}}\n\n" .
                "{{MITGLIEDER}}\n\n" .
                "Mit freundlichen Grüßen\n\n" .
                "{{BEARBEITER}}",
        ],
        'umwidmung' => [
            'subject' => 'Umwidmung Freistellung {{TK}} – {{TAGE}}',
            'body' =>
                "Sehr geehrte Damen und Herren,\n\n" .
                "hiermit teilen wir Ihnen eine Umwidmung der Freistellung für folgende Tage mit:\n\n" .
                "Zieltyp:           {{ZIELTYP}}\n" .
                "Datum/Tage:        {{TAGE}}\n\n" .
                "{{MITGLIEDER}}\n\n" .
                "Um Kenntnisnahme wird gebeten.\n\n" .
                "Mit freundlichen Grüßen\n\n" .
                "{{BEARBEITER}}",
        ],
    ];
}
}

if (!function_exists('storno_vorlagen_laden')) {
/** Liest beide gespeicherten Vorlagen, ergänzt fehlende um die Werkseinstellung. */
function storno_vorlagen_laden(PDO $pdo): array
{
    $json = get_einstellung($pdo, 'storno_umwidmung_mail_vorlagen');
    $gespeichert = $json ? json_decode($json, true) : null;
    if (!is_array($gespeichert)) $gespeichert = [];

    $defaults = storno_default_vorlagen();
    $out = [];
    foreach ($defaults as $typ => $default) {
        $v = $gespeichert[$typ] ?? null;
        $out[$typ] = (is_array($v) && !empty($v['subject']) && !empty($v['body']))
            ? ['subject' => $v['subject'], 'body' => $v['body']]
            : $default;
    }
    return $out;
}
}

if (!function_exists('storno_vorlage_laden')) {
/** Liest genau eine Vorlage (mit Fallback auf Werkseinstellung). */
function storno_vorlage_laden(PDO $pdo, string $typ): array
{
    $alle = storno_vorlagen_laden($pdo);
    return $alle[$typ] ?? storno_default_vorlagen()['storno'];
}
}

if (!function_exists('storno_mitglieder_liste_bauen')) {
/**
 * Baut den {{MITGLIEDER}}-Block (Ersatz für die bisherige
 * fest-programmierte Namensliste in build_storno_email_body()).
 * $mitglieder = [['vorname'=>, 'nachname'=>], ...]
 */
function storno_mitglieder_liste_bauen(array $mitglieder): string
{
    $namen = array_map(
        fn($m) => '– ' . trim($m['vorname'] . ' ' . $m['nachname']),
        $mitglieder
    );
    if (count($mitglieder) === 1) {
        return 'Mitglied:          ' . ltrim($namen[0], '– ');
    }
    return "Betroffene Mitglieder:\n" . implode("\n", $namen);
}
}

if (!function_exists('storno_render')) {
/** Ersetzt {{PLATZHALTER}} durch die übergebenen Werte. */
function storno_render(string $text, array $vars): string
{
    $search = array_map(fn($k) => '{{' . $k . '}}', array_keys($vars));
    return str_replace($search, array_values($vars), $text);
}
}
