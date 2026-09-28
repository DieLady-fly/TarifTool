<?php
// ============================================================
// _backend/erinnerung_helpers.php
// Tägliche Erinnerungsmail ans Büro: alle Anträge, deren
// ZUGEHÖRIGES EREIGNIS in genau 7 Tagen beginnt und die noch im
// Status 'freigabe_buero' (intern genehmigt, aber ggf. noch
// nicht beim AG beantragt/rückgemeldet) oder 'beantragt_ag'
// (beim AG beantragt, aber Rückmeldung steht noch aus) stehen.
//
// Zweck laut Anforderung: dem Büro ermöglichen zu prüfen, ob
// AG-Rückmeldungen evtl. nicht korrekt eingepflegt wurden, bzw.
// eine Erinnerung an den Arbeitgeber zu schicken.
//
// Empfänger: die "Büro Kontakt"-Adresse (dieselbe Einstellung
// 'storno_email_cc', die auch als CC bei Storno-Mails und als Empfänger
// der TK-Sitzungs-Freigabe-Mails dient, siehe buero_kontakt.php).
//
// Aufruf z.B. per Cron: siehe cron/erinnerung_frist.php
// ============================================================
require_once __DIR__ . '/mailer.php';

/**
 * @return array{versendet:bool, anzahl_antraege:int, anzahl_ereignisse:int, grund?:string}
 */
function erinnerung_frist_mail_senden(PDO $pdo, bool $force = false): array {
    // Idempotenz: nur einmal pro Kalendertag verschicken, auch wenn der
    // Cron aus Versehen mehrfach am selben Tag ausgelöst wird.
    $heute = date('Y-m-d');
    if (!$force) {
        $letzterLauf = get_einstellung($pdo, 'erinnerung_frist_letzter_lauf');
        if ($letzterLauf === $heute) {
            return ['versendet' => false, 'anzahl_antraege' => 0, 'anzahl_ereignisse' => 0, 'grund' => 'Heute bereits gelaufen.'];
        }
    }

    $frist_tage = (int)(get_einstellung($pdo, 'erinnerung_frist_tage') ?: 7);
    if ($frist_tage < 1) $frist_tage = 7;
    // Grenzdatum = heute + Frist-Tage. Erfasst werden Ereignisse, deren
    // Beginn AUF ODER VOR diesem Datum liegt ("<=", nicht nur exakt "="),
    // damit ab dem Stichtag jeden Tag erneut erinnert wird, solange sich
    // der Status (freigabe_buero/beantragt_ag) nicht geändert hat.
    // Zusätzlich: Ereignisse, deren Beginn bereits in der Vergangenheit
    // liegt, werden NICHT mehr aufgenommen - sie sind ohnehin nicht mehr
    // sinnvoll darstellbar/bearbeitbar, eine Erinnerung dafür wäre nur
    // verwirrend statt hilfreich.
    $grenzdatum = date('Y-m-d', strtotime("+{$frist_tage} days"));

    $stmt = $pdo->prepare(
        "SELECT a.id AS antrag_id, a.airline, a.vorname, a.nachname_enc, a.position,
                a.status AS antrag_status,
                e.id AS ereignis_id, e.bezeichnung AS ereignis_bezeichnung,
                e.veranstaltung, e.zeitraum_von, e.zeitraum_bis,
                tk.kuerzel AS tk_kuerzel
         FROM antraege a
         JOIN ereignisse e ON e.id = a.ereignis_id
         JOIN tks tk       ON tk.id = e.tk_id
         WHERE a.status IN ('freigabe_buero', 'beantragt_ag')
           AND e.zeitraum_von <= ?
           AND e.zeitraum_von >= CURDATE()
         ORDER BY e.zeitraum_von, a.airline, e.bezeichnung, a.vorname, a.nachname_enc"
    );
    $stmt->execute([$grenzdatum]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        if (!$force) set_einstellung($pdo, 'erinnerung_frist_letzter_lauf', $heute, 'cron');
        return ['versendet' => false, 'anzahl_antraege' => 0, 'anzahl_ereignisse' => 0, 'grund' => 'Keine offenen Anträge zum Stichtag.'];
    }

    // ── Gruppieren: Airline → Ereignis → Anträge ─────────────
    $airlines = [];
    $ereignisIds = [];
    foreach ($rows as $r) {
        $r['nachname'] = decrypt($r['nachname_enc']);
        $airlines[$r['airline']]['ereignisse'][$r['ereignis_id']]['meta'] = [
            'bezeichnung'  => $r['ereignis_bezeichnung'] ?: $r['veranstaltung'],
            'veranstaltung'=> $r['veranstaltung'],
            'zeitraum_von' => $r['zeitraum_von'],
            'zeitraum_bis' => $r['zeitraum_bis'],
        ];
        $airlines[$r['airline']]['ereignisse'][$r['ereignis_id']]['antraege'][] = $r;
        $ereignisIds[$r['ereignis_id']] = true;
    }
    ksort($airlines);

    // ── HTML bauen ────────────────────────────────────────────
    // Kurzbezeichnungen identisch zu status_badge() (helpers.php) und
    // assets/status.js - bei Änderungen dort ALLE drei Stellen anpassen.
    $statusLabel = ['freigabe_buero' => 'Freigabe Büro', 'beantragt_ag' => 'Beantragt bei AG'];

    $html = '<p>Die folgenden Anträge betreffen Ereignisse, die <strong>innerhalb von ' . $frist_tage
          . ' Tagen beginnen (oder bereits begonnen/vergangen sind)</strong> und stehen weiterhin im Status '
          . '„Freigabe Büro" (noch nicht/nicht sichtbar beim AG beantragt) oder „Beantragt bei AG" '
          . '(Rückmeldung steht noch aus) – das legt eine Prüfung nahe: entweder wurde die Rückmeldung des '
          . 'Arbeitgebers noch nicht eingepflegt, oder es lohnt sich, beim Arbeitgeber nachzuhaken. Diese Mail '
          . 'wird täglich erneut verschickt, solange sich der Status der betroffenen Anträge nicht ändert.</p>';

    $heuteTs = strtotime($heute);
    foreach ($airlines as $airline => $daten) {
        $html .= '<h3 style="margin:24px 0 8px;font-size:15px;color:#0f2744">' . htmlspecialchars($airline) . '</h3>';
        foreach ($daten['ereignisse'] as $ereignisId => $ed) {
            $meta = $ed['meta'];
            $restTage = (int)round((strtotime($meta['zeitraum_von']) - $heuteTs) / 86400);
            if ($restTage > 0) {
                $restLabel = "in {$restTage} Tag" . ($restTage === 1 ? '' : 'en');
                $restColor = '#7a8fa6';
            } elseif ($restTage === 0) {
                $restLabel = 'heute';
                $restColor = '#c0392b';
            } else {
                $restLabel = 'vor ' . abs($restTage) . ' Tag' . (abs($restTage) === 1 ? '' : 'en') . ' begonnen';
                $restColor = '#c0392b';
            }
            $html .= '<div style="margin:0 0 6px;font-size:13px;color:#333">'
                   . '<strong>' . htmlspecialchars($meta['bezeichnung']) . '</strong> ('
                   . htmlspecialchars($meta['veranstaltung']) . ') — '
                   . date('d.m.Y', strtotime($meta['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($meta['zeitraum_bis']))
                   . ' — <span style="color:' . $restColor . ';font-weight:700">' . $restLabel . '</span>'
                   . '</div>';
            $html .= '<table style="width:100%;border-collapse:collapse;margin:0 0 16px;font-size:13px">'
                   . '<tr style="background:#f0f2f5"><th style="text-align:left;padding:4px 8px">Mitglied</th>'
                   . '<th style="text-align:left;padding:4px 8px">TK</th>'
                   . '<th style="text-align:left;padding:4px 8px">Status</th></tr>';
            foreach ($ed['antraege'] as $a) {
                $html .= '<tr><td style="padding:4px 8px;border-top:1px solid #eee">'
                       . htmlspecialchars($a['nachname'] . ', ' . $a['vorname'] . ' (' . $a['position'] . ')')
                       . '</td><td style="padding:4px 8px;border-top:1px solid #eee">' . htmlspecialchars($a['tk_kuerzel']) . '</td>'
                       . '<td style="padding:4px 8px;border-top:1px solid #eee">' . htmlspecialchars($statusLabel[$a['antrag_status']] ?? $a['antrag_status']) . '</td></tr>';
            }
            $html .= '</table>';
        }
    }

    $html = _mail_wrap("Frist-Erinnerung: Ereignisse in {$frist_tage} Tagen", $html);

    // ── Empfänger: "Büro Kontakt" (dieselbe Adresse wie CC bei Storno-Mails
    // und Empfänger der TK-Sitzungs-Freigabe-Mails, siehe buero_kontakt.php) ──
    $buero_email = get_einstellung($pdo, 'storno_email_cc');
    if (!$buero_email || !filter_var($buero_email, FILTER_VALIDATE_EMAIL)) {
        return ['versendet' => false, 'anzahl_antraege' => count($rows), 'anzahl_ereignisse' => count($ereignisIds),
                'grund' => 'Keine gültige Büro-Kontakt-Adresse hinterlegt (siehe Tab „Büro Kontakt").'];
    }

    $subject = 'Frist-Erinnerung: ' . count($ereignisIds) . " Ereignis(se) in {$frist_tage} Tagen – Rückmeldung/Status prüfen";
    $gesendet = @_mail_send($buero_email, $subject, $html);

    if (!$force) set_einstellung($pdo, 'erinnerung_frist_letzter_lauf', $heute, 'cron');

    return [
        'versendet'         => $gesendet,
        'anzahl_antraege'   => count($rows),
        'anzahl_ereignisse' => count($ereignisIds),
        'frist_tage'        => $frist_tage,
    ];
}
