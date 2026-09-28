<?php
// ============================================================
// api/ag_antrag_anhang_download.php
// Liefert die in der Beantragungs-Vorschau erzeugten Anhänge
// (Excel-Freistellungsliste UND/ODER Word-Formular) zum Prüfen/
// Herunterladen im Büro-Panel aus. Zugriff NUR für eingeloggte
// Büro/Admin-Benutzer, per Token (siehe ag_antrag_anhang_token_ablegen()
// in ag_antrag_excel.php).
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/ag_antrag_excel.php';
send_security_headers();
session_start_secure();

if (empty($_SESSION['panel_user'])) {
    http_response_code(401);
    exit('Nicht angemeldet.');
}

$token = trim($_GET['token'] ?? '');
$datei = ag_antrag_anhang_aus_token($token);
if (!$datei) {
    http_response_code(404);
    exit('Datei nicht gefunden oder abgelaufen. Bitte Vorschau erneut öffnen.');
}

$ext = strtolower(pathinfo($datei['filename'], PATHINFO_EXTENSION));
$mimes = [
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($datei['filename']) . '"');
header('Content-Length: ' . filesize($datei['path']));
header('X-Content-Type-Options: nosniff');
readfile($datei['path']);
exit;
