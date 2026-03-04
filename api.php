<?php
/**
 * Weinkeller API — Daten für das Dashboard
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

$db = getDB();
$action = $_GET['action'] ?? 'overview';

switch ($action) {

    case 'overview':
        $stats = [];
        $stats['gesamt_flaschen'] = (int) $db->query("SELECT COALESCE(SUM(anzahl),0) FROM weine WHERE anzahl > 0 AND geloescht = 0")->fetchColumn();
        $stats['verschiedene_weine'] = (int) $db->query("SELECT COUNT(*) FROM weine WHERE anzahl > 0 AND geloescht = 0")->fetchColumn();
        $stats['gesamtwert'] = (float) $db->query("SELECT COALESCE(SUM(preis * anzahl),0) FROM weine WHERE anzahl > 0 AND preis IS NOT NULL AND geloescht = 0")->fetchColumn();
        $stats['durchschnitt_bewertung'] = round((float) $db->query("SELECT COALESCE(AVG(bewertung),0) FROM weine WHERE bewertung IS NOT NULL AND geloescht = 0")->fetchColumn(), 1);
        $stats['laender'] = (int) $db->query("SELECT COUNT(DISTINCT land) FROM weine WHERE land IS NOT NULL AND anzahl > 0 AND geloescht = 0")->fetchColumn();
        $stats['ueberfaellig'] = (int) $db->query("SELECT COUNT(*) FROM weine WHERE trinken_bis IS NOT NULL AND trinken_bis < YEAR(NOW()) AND anzahl > 0 AND geloescht = 0")->fetchColumn();
        $stats['favoriten'] = (int) $db->query("SELECT COUNT(*) FROM weine WHERE favorit = 1 AND anzahl > 0 AND geloescht = 0")->fetchColumn();
        $stats['total_eingelagert'] = (int) $db->query("SELECT COUNT(*) FROM weine WHERE geloescht = 0")->fetchColumn();
        echo json_encode($stats);
        break;

    case 'weine':
        $where = ["geloescht = 0"];
        $params = [];

        // Standard: nur vorrätige zeigen, es sei denn ?alle=1
        if (empty($_GET['alle']) || $_GET['alle'] !== '1') {
            $where[] = "anzahl > 0";
        }

        if (!empty($_GET['kategorie'])) { $where[] = "kategorie = ?"; $params[] = $_GET['kategorie']; }
        if (!empty($_GET['land'])) { $where[] = "land = ?"; $params[] = $_GET['land']; }
        if (!empty($_GET['jahrgang'])) { $where[] = "jahrgang = ?"; $params[] = (int)$_GET['jahrgang']; }
        if (!empty($_GET['rebsorte'])) { $where[] = "rebsorte = ?"; $params[] = $_GET['rebsorte']; }
        if (!empty($_GET['hersteller'])) { $where[] = "hersteller = ?"; $params[] = $_GET['hersteller']; }
        if (!empty($_GET['bewertung_min'])) { $where[] = "bewertung >= ?"; $params[] = (float)$_GET['bewertung_min']; }
        if (!empty($_GET['suche'])) {
            $where[] = "(name LIKE ? OR hersteller LIKE ? OR notiz LIKE ? OR rebsorte LIKE ? OR region LIKE ? OR lieferant LIKE ?)";
            $s = '%'.$_GET['suche'].'%';
            $params = array_merge($params, [$s,$s,$s,$s,$s,$s]);
        }
        if (isset($_GET['favorit']) && $_GET['favorit'] !== '') { $where[] = "favorit = ?"; $params[] = (int)$_GET['favorit']; }

        $sort = $_GET['sort'] ?? 'name';
        $allowed = ['name','jahrgang','kategorie','land','bewertung','anzahl','preis','trinken_bis','hersteller','alkohol','region'];
        if (!in_array($sort, $allowed)) $sort = 'name';
        $dir = (strtoupper($_GET['dir'] ?? 'ASC') === 'DESC') ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM weine WHERE " . implode(' AND ', $where) . " ORDER BY $sort $dir";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    case 'empfehlung':
        $sql = "SELECT *,
                CASE
                    WHEN trinken_bis IS NOT NULL AND trinken_bis < YEAR(NOW()) THEN 0
                    WHEN trinken_bis IS NOT NULL AND trinken_bis = YEAR(NOW()) THEN 1
                    WHEN trinken_bis IS NOT NULL AND trinken_bis <= YEAR(NOW())+1 THEN 2
                    ELSE 3
                END AS dringlichkeit
                FROM weine
                WHERE anzahl > 0 AND geloescht = 0
                ORDER BY dringlichkeit ASC, trinken_bis ASC, favorit DESC, bewertung DESC
                LIMIT 15";
        echo json_encode($db->query($sql)->fetchAll());
        break;

    case 'charts':
        $base = "FROM weine WHERE anzahl > 0 AND geloescht = 0";
        $charts = [];
        $charts['kategorie'] = $db->query("SELECT kategorie AS label, SUM(anzahl) AS value $base AND kategorie IS NOT NULL GROUP BY kategorie ORDER BY value DESC")->fetchAll();
        $charts['land'] = $db->query("SELECT land AS label, SUM(anzahl) AS value $base AND land IS NOT NULL GROUP BY land ORDER BY value DESC LIMIT 15")->fetchAll();
        $charts['jahrgang'] = $db->query("SELECT jahrgang AS label, SUM(anzahl) AS value $base AND jahrgang IS NOT NULL GROUP BY jahrgang ORDER BY jahrgang")->fetchAll();
        $charts['rebsorte'] = $db->query("SELECT rebsorte AS label, SUM(anzahl) AS value $base AND rebsorte IS NOT NULL GROUP BY rebsorte ORDER BY value DESC LIMIT 10")->fetchAll();
        $charts['bewertung'] = $db->query("SELECT ROUND(bewertung) AS label, COUNT(*) AS value FROM weine WHERE bewertung IS NOT NULL AND geloescht = 0 GROUP BY ROUND(bewertung) ORDER BY label")->fetchAll();
        $charts['meiste'] = $db->query("SELECT CONCAT(name, CASE WHEN jahrgang IS NOT NULL THEN CONCAT(' (', jahrgang, ')') ELSE '' END) AS label, anzahl AS value $base ORDER BY anzahl DESC LIMIT 10")->fetchAll();
        $charts['hersteller'] = $db->query("SELECT hersteller AS label, SUM(anzahl) AS value $base AND hersteller IS NOT NULL GROUP BY hersteller ORDER BY value DESC LIMIT 10")->fetchAll();
        echo json_encode($charts);
        break;

    case 'filter_options':
        $base = "FROM weine WHERE geloescht = 0";
        $options = [];
        $options['kategorien'] = $db->query("SELECT DISTINCT kategorie $base AND kategorie IS NOT NULL ORDER BY kategorie")->fetchAll(PDO::FETCH_COLUMN);
        $options['laender'] = $db->query("SELECT DISTINCT land $base AND land IS NOT NULL ORDER BY land")->fetchAll(PDO::FETCH_COLUMN);
        $options['jahrgaenge'] = $db->query("SELECT DISTINCT jahrgang $base AND jahrgang IS NOT NULL ORDER BY jahrgang DESC")->fetchAll(PDO::FETCH_COLUMN);
        $options['rebsorten'] = $db->query("SELECT DISTINCT rebsorte $base AND rebsorte IS NOT NULL ORDER BY rebsorte")->fetchAll(PDO::FETCH_COLUMN);
        $options['hersteller'] = $db->query("SELECT DISTINCT hersteller $base AND hersteller IS NOT NULL ORDER BY hersteller")->fetchAll(PDO::FETCH_COLUMN);
        $options['regionen'] = $db->query("SELECT DISTINCT region $base AND region IS NOT NULL ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($options);
        break;

    case 'lieferanten':
        echo json_encode($db->query("SELECT * FROM lieferanten ORDER BY name")->fetchAll());
        break;

    case 'datenqualitaet':
        $issues = [];

        // Kein Land
        $issues['kein_land'] = $db->query("SELECT id, name, jahrgang, hersteller, 'Kein Land angegeben' AS problem FROM weine WHERE (land IS NULL OR land = '') AND geloescht = 0 ORDER BY name")->fetchAll();

        // Keine Rebsorte
        $issues['keine_rebsorte'] = $db->query("SELECT id, name, jahrgang, hersteller, 'Keine Rebsorte angegeben' AS problem FROM weine WHERE (rebsorte IS NULL OR rebsorte = '') AND geloescht = 0 ORDER BY name")->fetchAll();

        // Kein Jahrgang (vintage war 1849 oder NULL)
        $issues['kein_jahrgang'] = $db->query("SELECT id, name, kategorie, hersteller, 'Kein Jahrgang angegeben' AS problem FROM weine WHERE jahrgang IS NULL AND geloescht = 0 ORDER BY name")->fetchAll();

        // Keine Kategorie/Typ
        $issues['keine_kategorie'] = $db->query("SELECT id, name, jahrgang, hersteller, 'Keine Kategorie (Rot/Weiß/Rosé)' AS problem FROM weine WHERE (kategorie IS NULL OR kategorie = '') AND geloescht = 0 ORDER BY name")->fetchAll();

        // Kein Preis bei vorrätigem Wein
        $issues['kein_preis'] = $db->query("SELECT id, name, jahrgang, hersteller, CONCAT('Kein Preis (', anzahl, ' Flaschen vorrätig)') AS problem FROM weine WHERE (preis IS NULL OR preis = 0) AND anzahl > 0 AND geloescht = 0 ORDER BY anzahl DESC")->fetchAll();

        // Keine Bewertung bei vorrätigem Wein
        $issues['keine_bewertung'] = $db->query("SELECT id, name, jahrgang, hersteller, CONCAT('Keine Bewertung (', anzahl, ' Flaschen)') AS problem FROM weine WHERE (bewertung IS NULL) AND anzahl > 0 AND geloescht = 0 ORDER BY anzahl DESC")->fetchAll();

        // Überfällig: trinken_bis < aktuelles Jahr, aber noch Flaschen da
        $issues['ueberfaellig'] = $db->query("SELECT id, name, jahrgang, hersteller, CONCAT('Trinkfenster seit ', trinken_bis, ' abgelaufen! Noch ', anzahl, 'x') AS problem FROM weine WHERE trinken_bis IS NOT NULL AND trinken_bis < YEAR(NOW()) AND anzahl > 0 AND geloescht = 0 ORDER BY trinken_bis ASC")->fetchAll();

        // Verdächtig alter Jahrgang (vor 2000, kein Lagerwein)
        $issues['alter_jahrgang'] = $db->query("SELECT id, name, jahrgang, hersteller, CONCAT('Sehr alter Jahrgang: ', jahrgang) AS problem FROM weine WHERE jahrgang IS NOT NULL AND jahrgang < 2000 AND jahrgang > 1900 AND anzahl > 0 AND geloescht = 0 ORDER BY jahrgang")->fetchAll();

        // Mögliche Duplikate (gleicher Name)
        $issues['duplikate'] = $db->query("SELECT GROUP_CONCAT(id) AS ids, name, COUNT(*) AS anzahl_eintraege, GROUP_CONCAT(DISTINCT jahrgang ORDER BY jahrgang) AS jahrgaenge, 'Mögliches Duplikat' AS problem FROM weine WHERE geloescht = 0 GROUP BY LOWER(name) HAVING COUNT(*) > 1 ORDER BY name")->fetchAll();

        // Mehr Flaschen entnommen als eingelagert (currentCount > initialCount wäre komisch, aber check negativ)
        $issues['bestand_negativ'] = $db->query("SELECT id, name, jahrgang, hersteller, CONCAT('Bestand ', anzahl, ' / eingelagert ', anzahl_initial, ' — stimmt was nicht?') AS problem FROM weine WHERE anzahl > anzahl_initial AND geloescht = 0 ORDER BY name")->fetchAll();

        // Kein Hersteller
        $issues['kein_hersteller'] = $db->query("SELECT id, name, jahrgang, land, 'Kein Hersteller/Weingut' AS problem FROM weine WHERE (hersteller IS NULL OR hersteller = '') AND geloescht = 0 ORDER BY name")->fetchAll();

        // Kein Trinkfenster (max_alter = 0)
        $issues['kein_trinkfenster'] = $db->query("SELECT id, name, jahrgang, hersteller, 'Kein Trinkfenster definiert' AS problem FROM weine WHERE (max_alter IS NULL OR max_alter = 0) AND anzahl > 0 AND geloescht = 0 ORDER BY name")->fetchAll();

        // Summary
        $summary = [];
        foreach ($issues as $key => $items) {
            $summary[] = ['typ' => $key, 'anzahl' => count($items)];
        }

        echo json_encode(['summary' => $summary, 'details' => $issues]);
        break;

    default:
        echo json_encode(['error' => 'Unbekannte Aktion']);
}
