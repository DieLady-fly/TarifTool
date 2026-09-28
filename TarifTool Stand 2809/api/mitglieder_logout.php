<?php
// ============================================================
// api/mitglieder_logout.php  –  Mitglieder-Session beenden
// ============================================================
require_once __DIR__ . '/../_backend/bootstrap.php';
require_once __DIR__ . '/../_backend/mitglieder_auth.php';
send_security_headers();

mitglied_logout();
header('Location: ../mitglieder_login.php');
exit;
