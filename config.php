<?php
/**
 * Weinkeller Dashboard — Konfiguration
 * Passe die DB-Credentials an deine MariaDB an.
 */
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'kellermeister');
define('DB_USER', 'kellermeister');
define('DB_PASS', 'pass');
define('DB_CHARSET', 'utf8mb4');

// Passwort für den Import-Bereich
define('IMPORT_PASSWORD', 'changeme');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
