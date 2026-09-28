<?php
// ============================================================
// _backend/auth.php  –  Session, CSRF, Authentifizierung
// ============================================================

function session_start_secure(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }
    // Session-Timeout - abhängig davon, welche Art Session aktiv ist:
    // TK-Mitglieder bekommen ein kürzeres Timeout (MITGLIED_SESSION_LIFETIME,
    // 15 Min.) als Büro/Admin/Finance (SESSION_LIFETIME, weiterhin 30 Min.).
    // Wichtig für api/antrag.php & Co., die von beiden Seiten genutzt werden -
    // hier wird zur Laufzeit anhand der vorhandenen Session-Daten entschieden,
    // nicht anhand der aufrufenden Datei.
    $timeout = !empty($_SESSION['mitglied_id'])
        ? (defined('MITGLIED_SESSION_LIFETIME') ? MITGLIED_SESSION_LIFETIME : 900)
        : SESSION_LIFETIME;
    if (!empty($_SESSION['_last_activity'])) {
        if (time() - $_SESSION['_last_activity'] > $timeout) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['_last_activity'] = time();
}

// ── CSRF ─────────────────────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(): bool {
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

function csrf_check(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        die(json_encode(['ok' => false, 'error' => 'Ungültiger Sicherheits-Token. Bitte Seite neu laden.']));
    }
}

// ── Mehrfachrollen ────────────────────────────────────────────
// panel_users.rolle enthält seit der Mehrfachrollen-Umstellung eine
// kommagetrennte Liste (z.B. "buero,finance") statt eines einzelnen
// Werts. Diese Funktion prüft, ob eine bestimmte Rolle enthalten ist -
// zentral genutzt statt einzelner "$_SESSION['panel_rolle'] === 'x'"-
// Vergleiche, die bei mehreren Rollen nie mehr zutreffen würden.
function panel_hat_rolle(string $rolle): bool {
    $rollen = array_map('trim', explode(',', $_SESSION['panel_rolle'] ?? ''));
    return in_array($rolle, $rollen, true);
}

// ── Panel-Login ───────────────────────────────────────────────
function require_login(string $min_rolle = 'buero'): array {
    session_start_secure();
    if (empty($_SESSION['panel_user'])) {
        // Relativer Redirect, funktioniert auf Strato ohne BASE_URL-Probleme
        $depth = substr_count($_SERVER['SCRIPT_NAME'], '/') - 1;
        $prefix = str_repeat('../', $depth);
        header('Location: ' . $prefix . 'buero_login.php');
        exit;
    }
    if ($min_rolle === 'admin' && !panel_hat_rolle('admin')) {
        http_response_code(403);
        die('<h1>Zugriff verweigert</h1><p>Sie benötigen Administrator-Rechte.</p>');
    }
    return ['user' => $_SESSION['panel_user'], 'rolle' => $_SESSION['panel_rolle']];
}

function panel_login(string $username, string $password): ?array {
    $stmt = db()->prepare("SELECT * FROM panel_users WHERE username = ? AND aktiv = 1 LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        return $user;
    }
    return null;
}
