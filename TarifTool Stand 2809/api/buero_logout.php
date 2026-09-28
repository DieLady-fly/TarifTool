<?php
// api/logout.php
require_once __DIR__ . '/../_backend/bootstrap.php';
session_start_secure();
session_unset();
session_destroy();
header('Location: ../buero_login.php');
exit;
