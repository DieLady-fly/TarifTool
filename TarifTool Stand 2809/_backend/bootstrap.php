<?php
// ============================================================
// _backend/bootstrap.php  –  Lädt alle Backend-Komponenten
// Einzige require-Zeile, die Frontend/API braucht:
//   require_once __DIR__ . '/_backend/bootstrap.php';
// ============================================================

// Fehleranzeige zentral hier deaktivieren statt nur in index.php - gilt
// dadurch für ausnahmslos jede Datei, die bootstrap.php einbindet
// (Mitglieder-Portal, Büro-Panel, Admin-Panel, alle api/*.php). Fehler
// weiterhin protokollieren, nur nicht an den Browser ausgeben, damit
// keine Stack-Traces/Pfade/Query-Fragmente nach außen sichtbar werden.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$_backend = __DIR__;

require_once $_backend . '/config.php';
require_once $_backend . '/db.php';
require_once $_backend . '/crypto.php';
require_once $_backend . '/auth.php';
require_once $_backend . '/helpers.php';
require_once $_backend . '/mailer.php';
require_once __DIR__ . '/tk_provision.php';
require_once __DIR__ . '/mitglieder_auth.php';
require_once __DIR__ . '/mail_mitglieder.php';


