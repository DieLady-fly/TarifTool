<?php
// ============================================================
// _backend/db.php  –  PDO-Datenbankverbindung (Singleton)
//
// Azure Database for MySQL Flexible Server verlangt standardmäßig eine
// SSL/TLS-Verbindung (require_secure_transport=ON). Der mysql-CLI-Client
// handhabt das automatisch, PDO/mysqlnd braucht dafür die explizite
// SSL_CA-Option mit dem Azure-Root-Zertifikat - sonst meldet der Server
// die verweigerte, unverschlüsselte Verbindung irreführend als
// "Access denied" statt als eigenen SSL-Fehler.
//
// Seit PHP 8.4 gibt es die treiberspezifische Klasse Pdo\Mysql mit
// eigenen Konstanten (Pdo\Mysql::ATTR_SSL_CA); die alten PDO::MYSQL_*-
// Konstanten sind seit PHP 8.5 deprecated. Damit dieselbe Datei sowohl
// auf PHP 8.5 (aktuell, Azure) als auch auf älteren PHP-Versionen ohne
// Warnung läuft, wird zur Laufzeit geprüft, welche Variante verfügbar
// ist - kein hartes Mindestversions-Erfordernis.
// ============================================================

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);

        // Ab PHP 8.4 vorhanden: Pdo\Mysql::ATTR_SSL_CA / ATTR_SSL_VERIFY_SERVER_CERT
        if (class_exists('Pdo\\Mysql') && defined('Pdo\\Mysql::ATTR_SSL_CA')) {
            $sslCaKey     = constant('Pdo\\Mysql::ATTR_SSL_CA');
            $sslVerifyKey = constant('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT');
        } else {
            // Fallback für PHP < 8.4 (dort noch nicht deprecated)
            $sslCaKey     = PDO::MYSQL_ATTR_SSL_CA;
            $sslVerifyKey = PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT;
        }

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            $sslCaKey                    => __DIR__ . '/DigiCertGlobalRootG2.crt.pem',
            $sslVerifyKey                => true,
        ]);
    }
    return $pdo;
}
