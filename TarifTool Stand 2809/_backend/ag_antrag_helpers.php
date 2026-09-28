<?php
// ============================================================
// _backend/ag_antrag_helpers.php  –  Hilfsfunktionen für die
// Sammel-Beantragungsmails beim Arbeitgeber (Status
// freigabe_buero -> beantragt_ag).
//
// Es gibt DREI vom Büro anpassbare Vorlagen (Betreff + Text), je nach
// Situation:
//   - standard      : reguläre Beantragung
//   - nachstichtag   : Beantragung nach dem Meldestichtag der Airline
//   - kurzfristig   : kurzfristige Beantragung
//
// Alle drei werden gemeinsam als EIN JSON-Objekt unter dem Einstellungs-
// Key 'ag_antrag_mail_vorlagen' gespeichert (system_einstellungen, wie
// schon "Büro Kontakt"). Fehlt eine Vorlage (oder alle), greift die
// jeweilige Werkseinstellung aus ag_antrag_default_vorlagen().
//
// Platzhalter in Betreff/Text: {{AIRLINE}} {{VON}} {{BIS}} {{LISTE}}
// {{BEARBEITER}} {{ANZAHL}}
//
// Verwendet von: api/buero.php (case 'get_ag_mail_vorlagen',
// 'save_ag_mail_vorlage', 'get_ag_antragsmail_vorschau',
// 'sende_ag_antragsmail')
// ============================================================

if (!function_exists('ag_antrag_vorlage_typen')) {
/** Erlaubte Vorlagen-Typen mit Anzeigenamen. */
function ag_antrag_vorlage_typen(): array
{
    return [
        'standard'     => 'Beantragung',
        'nachstichtag' => 'Beantragung nach Stichtag',
        'kurzfristig'  => 'Kurzfristige Beantragung',
    ];
}
}

if (!function_exists('ag_antrag_default_vorlagen')) {
/** Werkseinstellungen aller drei Vorlagen. */
function ag_antrag_default_vorlagen(): array
{
    return [
        'standard' => [
            'subject' => 'Beantragung Freistellung {{AIRLINE}} – {{VON}} bis {{BIS}}',
            'body' =>
                "Sehr geehrte Damen und Herren,\n\n" .
                "hiermit beantragen wir für folgende Mitarbeitende die Freistellung für den Tarifausschuss:\n\n" .
                "{{LISTE}}\n\n" .
                "Um Rückmeldung wird gebeten.\n\n" .
                "Mit freundlichen Grüßen\n\n" .
                "{{BEARBEITER}}",
        ],
        'nachstichtag' => [
            'subject' => 'Nachmeldung Freistellung {{AIRLINE}} – {{VON}} bis {{BIS}}',
            'body' =>
                "Sehr geehrte Damen und Herren,\n\n" .
                "die folgende Freistellungsbeantragung erreicht Sie leider erst nach dem regulären Meldestichtag. " .
                "Wir bitten trotzdem um wohlwollende Prüfung und Berücksichtigung:\n\n" .
                "{{LISTE}}\n\n" .
                "Um Rückmeldung wird gebeten.\n\n" .
                "Mit freundlichen Grüßen\n\n" .
                "{{BEARBEITER}}",
        ],
        'kurzfristig' => [
            'subject' => 'Kurzfristige Beantragung Freistellung {{AIRLINE}} – {{VON}} bis {{BIS}}',
            'body' =>
                "Sehr geehrte Damen und Herren,\n\n" .
                "aus aktuellem Anlass beantragen wir kurzfristig die Freistellung für folgende Mitarbeitende. " .
                "Wir bitten um schnellstmögliche Rückmeldung:\n\n" .
                "{{LISTE}}\n\n" .
                "Vielen Dank für Ihr Verständnis.\n\n" .
                "Mit freundlichen Grüßen\n\n" .
                "{{BEARBEITER}}",
        ],
    ];
}
}

if (!function_exists('ag_antrag_default_vorlagen_en')) {
/** English default templates. */
function ag_antrag_default_vorlagen_en(): array
{
    return [
        'standard' => [
            'subject' => 'Request for Leave of Absence {{AIRLINE}} – {{VON}} to {{BIS}}',
            'body' =>
                "Dear Sir or Madam,\n\n" .
                "we hereby request leave of absence for the following employees for the purposes of the Collective Bargaining Committee:\n\n" .
                "{{LISTE}}\n\n" .
                "We kindly ask for your confirmation.\n\n" .
                "Kind regards,\n\n" .
                "{{BEARBEITER}}",
        ],
        'nachstichtag' => [
            'subject' => 'Late Submission – Request for Leave of Absence {{AIRLINE}} – {{VON}} to {{BIS}}',
            'body' =>
                "Dear Sir or Madam,\n\n" .
                "please note that the following leave of absence request is submitted after the regular deadline. " .
                "We kindly ask for your consideration nonetheless:\n\n" .
                "{{LISTE}}\n\n" .
                "We kindly ask for your confirmation.\n\n" .
                "Kind regards,\n\n" .
                "{{BEARBEITER}}",
        ],
        'kurzfristig' => [
            'subject' => 'Short-Notice Request for Leave of Absence {{AIRLINE}} – {{VON}} to {{BIS}}',
            'body' =>
                "Dear Sir or Madam,\n\n" .
                "due to current circumstances, we are requesting leave of absence on short notice for the following employees. " .
                "We kindly ask for your prompt response:\n\n" .
                "{{LISTE}}\n\n" .
                "Thank you for your understanding.\n\n" .
                "Kind regards,\n\n" .
                "{{BEARBEITER}}",
        ],
    ];
}
}

if (!function_exists('ag_antrag_vorlagen_laden')) {
/** Liest alle drei gespeicherten Vorlagen, ergänzt fehlende um die Werkseinstellung. */
function ag_antrag_vorlagen_laden(PDO $pdo): array
{
    $json = get_einstellung($pdo, 'ag_antrag_mail_vorlagen');
    $gespeichert = $json ? json_decode($json, true) : null;
    if (!is_array($gespeichert)) $gespeichert = [];

    $defaults = ag_antrag_default_vorlagen();
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

if (!function_exists('ag_antrag_vorlage_laden')) {
/** Liest genau eine Vorlage (mit Fallback auf Werkseinstellung). */
function ag_antrag_vorlage_laden(PDO $pdo, string $typ, string $sprache = 'de'): array
{
    if ($sprache === 'en') {
        return ag_antrag_default_vorlagen_en()[$typ] ?? ag_antrag_default_vorlagen_en()['standard'];
    }
    $alle = ag_antrag_vorlagen_laden($pdo);
    return $alle[$typ] ?? ag_antrag_default_vorlagen()['standard'];
}
}

if (!function_exists('ag_antrag_liste_bauen')) {
/**
 * Baut den {{LISTE}}-Block: eine Zeile pro Mitglied.
 * $mitglieder = [['vorname'=>, 'nachname'=>, 'zeitraum_von'=>, 'zeitraum_bis'=>, 'veranstaltung'=>], ...]
 */
function ag_antrag_liste_bauen(array $mitglieder, string $sprache = 'de'): string
{
    $fmt = fn($d) => implode('.', array_reverse(explode('-', $d)));
    // Zeilen gruppieren: gleicher Name+Veranstaltung an aufeinanderfolgenden Tagen
    // werden zu Bereichen zusammengefasst (z.B. "12.10. – 14.10.")
    $gruppen = [];
    foreach ($mitglieder as $m) {
        $key = $m['vorname'] . '|' . $m['nachname'] . '|' . $m['veranstaltung'];
        $gruppen[$key]['vorname']      = $m['vorname'];
        $gruppen[$key]['nachname']     = $m['nachname'];
        $gruppen[$key]['veranstaltung']= $m['veranstaltung'];
        $gruppen[$key]['tage'][]       = $m['zeitraum_von'];
    }
    $zeilen = array_map(function ($g) use ($fmt, $sprache) {
        sort($g['tage']);
        // Aufeinanderfolgende Tage zu Bereichen
        $bereiche = [];
        $start = $end = $g['tage'][0];
        for ($i = 1; $i < count($g['tage']); $i++) {
            $prev = new DateTime($end);
            $prev->modify('+1 day');
            if ($prev->format('Y-m-d') === $g['tage'][$i]) {
                $end = $g['tage'][$i];
            } else {
                $bereiche[] = $start === $end ? $fmt($start) : $fmt($start) . ' – ' . $fmt($end);
                $start = $end = $g['tage'][$i];
            }
        }
        $bereiche[] = $start === $end ? $fmt($start) : $fmt($start) . ' – ' . $fmt($end);
        $zr = implode(', ', $bereiche);
        $veranstaltung = $sprache === 'en'
            ? ag_antrag_veranstaltung_en($g['veranstaltung'])
            : $g['veranstaltung'];
        return '– ' . trim($g['vorname'] . ' ' . $g['nachname']) . ': ' . $zr . ' (' . $veranstaltung . ')';
    }, array_values($gruppen));
    return implode("\n", $zeilen);
}
}

if (!function_exists('ag_antrag_veranstaltung_en')) {
/** Übersetzt Veranstaltungstypen ins Englische. */
function ag_antrag_veranstaltung_en(string $typ): string
{
    return match($typ) {
        'Verhandlung' => 'Collective Bargaining',
        'TK Sitzung'  => 'Committee Meeting',
        'sonstige'    => 'Other',
        default       => $typ,
    };
}
}

if (!function_exists('ag_antrag_render')) {
/** Ersetzt {{PLATZHALTER}} durch die übergebenen Werte. */
function ag_antrag_render(string $text, array $vars): string
{
    $search = array_map(fn($k) => '{{' . $k . '}}', array_keys($vars));
    return str_replace($search, array_values($vars), $text);
}
}
