<?php
/**
 * Keller-Snapshot erstellen
 * 
 * Dieses Script speichert den aktuellen Kellerwert als täglichen Snapshot.
 * Per Cronjob einmal täglich aufrufen:
 *   0 3 * * * php /pfad/zu/dashboard/snapshot.php
 * 
 * Oder manuell: php snapshot.php
 * Oder per Browser: https://dein-server/dashboard/snapshot.php?key=DEIN_KEY
 */
require_once __DIR__ . '/config.php';

// Wenn als Include aufgerufen → kein Output
$isIncluded = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'snapshot.php');

// Optional: einfacher API-Key-Schutz für Browser-Aufruf
// define('SNAPSHOT_KEY', 'changeme'); // In config.php setzen
if ((php_sapi_name() !== 'cli' && defined('SNAPSHOT_KEY')) and !$isIncluded) {
    if (($_GET['key'] ?? '') !== SNAPSHOT_KEY) {
        http_response_code(403);
        die('Unauthorized');
    }
}

$db = getDB();

$sql = "INSERT INTO keller_snapshots (datum, gesamt_flaschen, verschiedene_weine, kellerwert, durchschnitt_preis, durchschnitt_bewertung, laender, favoriten)
SELECT 
    CURDATE(),
    COALESCE(SUM(anzahl), 0),
    COUNT(*),
    COALESCE(SUM(preis * anzahl), 0),
    COALESCE(AVG(CASE WHEN preis > 0 THEN preis END), 0),
    COALESCE(AVG(bewertung), 0),
    COUNT(DISTINCT CASE WHEN land IS NOT NULL AND land != '' THEN land END),
    SUM(CASE WHEN favorit = 1 THEN 1 ELSE 0 END)
FROM weine 
WHERE anzahl > 0 AND geloescht = 0
ON DUPLICATE KEY UPDATE
    gesamt_flaschen = VALUES(gesamt_flaschen),
    verschiedene_weine = VALUES(verschiedene_weine),
    kellerwert = VALUES(kellerwert),
    durchschnitt_preis = VALUES(durchschnitt_preis),
    durchschnitt_bewertung = VALUES(durchschnitt_bewertung),
    laender = VALUES(laender),
    favoriten = VALUES(favoriten)";

$db->exec($sql);




$msg = "Snapshot für " . date('Y-m-d') . " gespeichert.";


if (!$isIncluded) {
	if (php_sapi_name() === 'cli') {
		echo $msg . "\n";
	} else {
		header('Content-Type: application/json');
		echo json_encode(['success' => true, 'message' => $msg]);
	}
}