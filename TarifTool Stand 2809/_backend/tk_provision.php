<?php
// ============================================================
// _backend/tk_provision.php
// Legt DB-Views für eine TK an bzw. entfernt sie.
// Wird von api/admin.php bei create_tk / delete_tk aufgerufen.
// ============================================================

/**
 * Erzeugt einen URL-sicheren Slug aus dem TK-Kürzel.
 */
function tk_slug(string $kuerzel): string {
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $kuerzel));
}

/**
 * Legt alle Views für eine TK an (oder ersetzt sie).
 * Sicher: verwendet keine User-Eingaben in SQL-Bezeichnern –
 * $slug ist durch tk_slug() auf [a-z0-9_] beschränkt.
 */
function tk_provision_create(int $tk_id, string $kuerzel): void {
    $slug = tk_slug($kuerzel);
    $db   = db();

    // Anträge dieser TK
    $db->exec("CREATE OR REPLACE VIEW v_antraege_{$slug} AS
        SELECT * FROM antraege WHERE tk_id = {$tk_id}");

    // Ereignisse dieser TK
    $db->exec("CREATE OR REPLACE VIEW v_ereignisse_{$slug} AS
        SELECT * FROM ereignisse WHERE tk_id = {$tk_id}");

    // antrag_tage (via Join, damit tk_id implizit geprüft wird)
    $db->exec("CREATE OR REPLACE VIEW v_antrag_tage_{$slug} AS
        SELECT at.*
        FROM antrag_tage at
        JOIN antraege a ON a.id = at.antrag_id
        WHERE a.tk_id = {$tk_id}");

    // Mitglieder (ohne sensible Felder)
    $db->exec("CREATE OR REPLACE VIEW v_mitglieder_{$slug} AS
        SELECT id, tk_id, vorname, email_hash, aktiv, erstellt_am, letzter_login
        FROM mitglieder
        WHERE tk_id = {$tk_id}");
}

/**
 * Entfernt alle Views einer TK (beim Löschen der TK).
 */
function tk_provision_drop(int $tk_id, string $kuerzel): void {
    $slug = tk_slug($kuerzel);
    $db   = db();
    $db->exec("DROP VIEW IF EXISTS v_antraege_{$slug}");
    $db->exec("DROP VIEW IF EXISTS v_ereignisse_{$slug}");
    $db->exec("DROP VIEW IF EXISTS v_antrag_tage_{$slug}");
    $db->exec("DROP VIEW IF EXISTS v_mitglieder_{$slug}");
}

/**
 * Provisioning für ALLE aktiven TKs – einmaliger Aufruf
 * z.B. nach der Migration.
 */
function tk_provision_all(): void {
    $tks = db()->query("SELECT id, kuerzel FROM tks")->fetchAll();
    foreach ($tks as $tk) {
        tk_provision_create((int)$tk['id'], $tk['kuerzel']);
    }
}
