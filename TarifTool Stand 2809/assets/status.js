// ============================================================
// assets/status.js
// Zentrale, einheitliche Status-Bezeichnungen und -Farben für
// das gesamte Büro-Panel. Von jeder Seite eingebunden statt
// eigener, lokal abweichender Label-Listen (Grund für die
// frühere Uneinheitlichkeit: "Abgel. AG" / "Abgelehnt AG" /
// "Abgel. (Arbeitgeber)" für denselben Status-Code).
//
// Neue Status-Codes bitte AUSSCHLIESSLICH hier ergänzen, nicht
// erneut lokal in einer einzelnen Seite.
// ============================================================
const STATUS_MAP = {
  ausstehend:           ['Beantragt durch Mitglied', '#856404', '#fff3cd'],
  freigabe_buero:       ['Genehmigt durch Büro',   '#1d4ed8', '#dbeafe'],
  beantragt_ag:         ['Beantragt bei AG',      '#2851a3', '#e0ecff'],
  genehmigt:            ['Genehmigt durch AG',    '#1e7e34', '#e6f4ea'],
  abgelehnt:            ['Abgelehnt durch Büro',  '#c0392b', '#fde8e8'],
  abgelehnt_ag:         ['Abgelehnt durch Arbeitgeber', '#7d1a1a', '#fde8e8'],
  storno_buero:         ['Storno beim AG beantragt', '#5a4a00', '#f5f0e0'],
  storno_bestaetigt_ag: ['Storno bestätigt (AG)', '#1e7e34', '#e6f4ea'],
};

function statusLabel(status) {
  return (STATUS_MAP[status] || [status])[0];
}

function statusBadge(status) {
  const [label, color, bg] = STATUS_MAP[status] || [status, '#333', '#eee'];
  return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;background:${bg};color:${color}">${label}</span>`;
}
