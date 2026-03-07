-- Weinkeller Dashboard — Datenbank Setup
CREATE DATABASE IF NOT EXISTS kellermeister CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kellermeister;

CREATE TABLE IF NOT EXISTS weine (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    jahrgang INT DEFAULT NULL,
    kategorie VARCHAR(100) DEFAULT NULL COMMENT 'Rotwein/Weißwein/Rosé/Sekt/Blanc de Noir',
    land VARCHAR(100) DEFAULT NULL,
    region VARCHAR(150) DEFAULT NULL,
    appellation VARCHAR(150) DEFAULT NULL,
    rebsorte VARCHAR(150) DEFAULT NULL,
    hersteller VARCHAR(200) DEFAULT NULL,
    lieferant VARCHAR(200) DEFAULT NULL,
    lagerort VARCHAR(150) DEFAULT NULL,
    volumen DECIMAL(6,2) DEFAULT NULL COMMENT 'Liter',
    anzahl_initial INT DEFAULT 0,
    anzahl INT DEFAULT 0,
    preis DECIMAL(10,2) DEFAULT NULL,
    waehrung VARCHAR(10) DEFAULT 'EUR',
    alkohol DECIMAL(4,1) DEFAULT NULL,
    bewertung DECIMAL(3,1) DEFAULT NULL COMMENT '0-5 Sterne',
    trinken_ab INT DEFAULT NULL COMMENT 'Jahrgang + minAge',
    trinken_bis INT DEFAULT NULL COMMENT 'Jahrgang + maxAge',
    min_alter INT DEFAULT NULL,
    max_alter INT DEFAULT NULL,
    notiz TEXT DEFAULT NULL,
    favorit TINYINT(1) DEFAULT 0,
    barcode VARCHAR(100) DEFAULT NULL,
    geloescht TINYINT(1) DEFAULT 0,
    keller_name VARCHAR(200) DEFAULT NULL,
    erstellt DATETIME DEFAULT CURRENT_TIMESTAMP,
    aktualisiert DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_uuid (uuid),
    INDEX idx_kategorie (kategorie),
    INDEX idx_land (land),
    INDEX idx_jahrgang (jahrgang),
    INDEX idx_bewertung (bewertung),
    INDEX idx_trinken_bis (trinken_bis),
    INDEX idx_anzahl (anzahl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lieferanten (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    telefon VARCHAR(100) DEFAULT NULL,
    strasse VARCHAR(255) DEFAULT NULL,
    plz VARCHAR(20) DEFAULT NULL,
    stadt VARCHAR(100) DEFAULT NULL,
    land VARCHAR(100) DEFAULT NULL,
    kommentar TEXT DEFAULT NULL,
    UNIQUE KEY idx_uuid (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    dateiname VARCHAR(255),
    zeilen_gesamt INT DEFAULT 0,
    zeilen_neu INT DEFAULT 0,
    zeilen_aktualisiert INT DEFAULT 0,
    importiert_am DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Neue Tabelle für Kellerwert-Verlauf
CREATE TABLE IF NOT EXISTS keller_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    datum DATE NOT NULL,
    gesamt_flaschen INT NOT NULL DEFAULT 0,
    verschiedene_weine INT NOT NULL DEFAULT 0,
    kellerwert DECIMAL(10,2) NOT NULL DEFAULT 0,
    durchschnitt_preis DECIMAL(8,2) DEFAULT NULL,
    durchschnitt_bewertung DECIMAL(3,1) DEFAULT NULL,
    laender INT NOT NULL DEFAULT 0,
    favoriten INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_datum (datum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
