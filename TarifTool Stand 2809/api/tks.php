<?php
require_once __DIR__ . '/../_backend/bootstrap.php';
send_security_headers();
header('Content-Type: application/json; charset=utf-8');

$tks = db()->query(
    "SELECT id, kuerzel, bezeichnung, airline FROM tks WHERE aktiv = 1 ORDER BY kuerzel"
)->fetchAll();

foreach ($tks as &$tk) {
    $tk['airlines'] = $tk['airline'] ? [$tk['airline']] : [];
    unset($tk['airline']);
}

json_ok($tks);
