<?php
/**
 * Kellermeister XML Import → MariaDB
 * Passwortgeschützt über IMPORT_PASSWORD in config.php
 */
require_once __DIR__ . '/config.php';

// ── Auth prüfen ──
session_start();
$authenticated = isset($_SESSION['import_auth']) && $_SESSION['import_auth'] === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === IMPORT_PASSWORD) {
        $_SESSION['import_auth'] = true;
        $authenticated = true;
    } else {
        $authError = 'Falsches Passwort!';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    session_destroy();
    header('Location: import.php');
    exit;
}

// ── Login-Seite ──
if (!$authenticated) {
    ?><!DOCTYPE html>
    <html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>🔒 Import Login</title>
    <style>
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #1a1a2e; color: #eee; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .login { background: #16213e; padding: 40px; border-radius: 12px; text-align: center; border: 1px solid rgba(233,69,96,0.3); }
        .login h2 { color: #e94560; margin-bottom: 20px; }
        input[type=password] { background: #1a1a2e; color: #eee; border: 1px solid #0f3460; padding: 12px 20px; border-radius: 6px; font-size: 1em; width: 250px; }
        input[type=password]:focus { outline: none; border-color: #e94560; }
        button { background: #e94560; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 1em; margin-top: 12px; }
        button:hover { opacity: 0.8; }
        .error { color: #ef4444; margin-top: 12px; }
    </style></head><body>
    <form class="login" method="POST">
        <h2>🔒 Import-Bereich</h2>
        <p>Passwort eingeben um Weine zu importieren:</p><br>
        <input type="password" name="password" placeholder="Passwort" autofocus required><br>
        <button type="submit">Einloggen</button>
        <?php if (isset($authError)): ?><p class="error"><?= htmlspecialchars($authError) ?></p><?php endif; ?>
    </form>
    </body></html><?php
    exit;
}

// ── XML Import verarbeiten ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['xml'])) {
    header('Content-Type: application/json; charset=utf-8');

    if ($_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'Datei-Upload fehlgeschlagen']);
        exit;
    }

    $xmlContent = file_get_contents($_FILES['xml']['tmp_name']);
    $filename = $_FILES['xml']['name'];

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if ($xml === false) {
        $errors = array_map(fn($e) => $e->message, libxml_get_errors());
        echo json_encode(['error' => 'XML nicht lesbar', 'details' => $errors]);
        exit;
    }

    $db = getDB();
    $neu = 0;
    $aktualisiert = 0;
    $gesamt = 0;
    $lieferanten_neu = 0;
    $fehler = [];

    // Alle Keller und Storages durchgehen
    foreach ($xml->cellar as $cellar) {
        $kellerName = (string) $cellar['name'];

        foreach ($cellar->storage as $storage) {
            $gesamt++;
            try {
                $uuid = (string) $storage->uuid;
                $name = (string) $storage['name'];
                $vintage = (string) $storage->vintage;
                $country = (string) $storage->country;
                $producer = (string) $storage->producer;
                $region = (string) $storage->region;
                $appellation = (string) $storage->appellation;
                $type = (string) $storage->type;
                $volume = (float) $storage->volume;
                $varietal = trim((string) $storage->varietal);
                $initialCount = (int) $storage->initialStorageCount;
                $currentCount = (int) $storage->currentStorageCount;
                $priceNode = $storage->price;
                $price = (float) $priceNode;
                $currency = (string) ($priceNode['currency'] ?? 'EUR');
                $alcohol = (float) $storage->alcoholStrength;
                $rating = (float) $storage->rating;
                $note = (string) $storage->note;
                $minAge = (int) $storage->minAge;
                $maxAge = (int) $storage->maxAge;
                $favourite = (int) $storage->favourite;
                $scancode = (string) $storage->scancode;
                $deleted = ((string) $storage->deleted) === 'true' ? 1 : 0;

                // Trinken ab/bis berechnen
                $jahrgang = ($vintage && $vintage !== '1849') ? (int) $vintage : null; // 1849 = "kein Jahrgang" in Kellermeister
                $trinkenAb = ($jahrgang && $minAge > 0) ? $jahrgang + $minAge : null;
                $trinkenBis = ($jahrgang && $maxAge > 0) ? $jahrgang + $maxAge : null;

                // Preis bereinigen (0 = nicht angegeben)
                $price = ($price > 0) ? $price : null;
                // Alkohol bereinigen (-1 = nicht angegeben)
                $alcohol = ($alcohol > 0) ? $alcohol : null;
                // Rating bereinigen (0 = nicht bewertet)
                $rating = ($rating > 0) ? $rating : null;
                // Volume bereinigen (0 = nicht angegeben)
                $volume = ($volume > 0) ? $volume : null;

                // Barcode bereinigen (URLs rausfiltern)
                if ($scancode && (str_starts_with($scancode, 'http') || strlen($scancode) > 30)) {
                    $scancode = null;
                }

                // Lieferant importieren
                $lieferantName = null;
                if (isset($storage->supplierCellar)) {
                    $sc = $storage->supplierCellar;
                    $lieferantName = (string) $sc['name'];
                    $scUuid = (string) $sc['uuid'];

                    if ($scUuid && isset($sc->owner)) {
                        $owner = $sc->owner;
                        $stmt = $db->prepare("INSERT INTO lieferanten (uuid, name, email, website, telefon, strasse, plz, stadt, land, kommentar)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), website=VALUES(website),
                            telefon=VALUES(telefon), strasse=VALUES(strasse), plz=VALUES(plz), stadt=VALUES(stadt),
                            land=VALUES(land), kommentar=VALUES(kommentar)");
                        $stmt->execute([
                            $scUuid,
                            (string) $owner['name'] ?: $lieferantName,
                            (string) $owner->email ?: null,
                            (string) $owner->website ?: null,
                            (string) $owner->phone ?: null,
                            (string) $owner->street ?: null,
                            (string) $owner->zip ?: null,
                            (string) $owner->city ?: null,
                            (string) $owner->country ?: null,
                            (string) $owner->comment ?: null,
                        ]);
                        if ($stmt->rowCount() === 1) $lieferanten_neu++;
                    }
                }

                // Wein upsert (UUID als unique key)
                $sql = "INSERT INTO weine (uuid, name, jahrgang, kategorie, land, region, appellation, rebsorte,
                        hersteller, lieferant, volumen, anzahl_initial, anzahl, preis, waehrung, alkohol,
                        bewertung, trinken_ab, trinken_bis, min_alter, max_alter, notiz, favorit,
                        barcode, geloescht, keller_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        name=VALUES(name), jahrgang=VALUES(jahrgang), kategorie=VALUES(kategorie),
                        land=VALUES(land), region=VALUES(region), appellation=VALUES(appellation),
                        rebsorte=VALUES(rebsorte), hersteller=VALUES(hersteller), lieferant=VALUES(lieferant),
                        volumen=VALUES(volumen), anzahl_initial=VALUES(anzahl_initial), anzahl=VALUES(anzahl),
                        preis=VALUES(preis), waehrung=VALUES(waehrung), alkohol=VALUES(alkohol),
                        bewertung=VALUES(bewertung), trinken_ab=VALUES(trinken_ab), trinken_bis=VALUES(trinken_bis),
                        min_alter=VALUES(min_alter), max_alter=VALUES(max_alter), notiz=VALUES(notiz),
                        favorit=VALUES(favorit), barcode=VALUES(barcode), geloescht=VALUES(geloescht),
                        keller_name=VALUES(keller_name)";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $uuid, $name, $jahrgang, $type ?: null, $country ?: null, $region ?: null,
                    $appellation ?: null, $varietal ?: null, $producer ?: null, $lieferantName,
                    $volume, $initialCount, $currentCount, $price, $currency, $alcohol,
                    $rating, $trinkenAb, $trinkenBis, $minAge, $maxAge, $note ?: null,
                    $favourite, $scancode, $deleted, $kellerName
                ]);

                if ($stmt->rowCount() === 1) $neu++;
                elseif ($stmt->rowCount() === 2) $aktualisiert++;

            } catch (\Exception $e) {
                $fehler[] = "Wein '$name': " . $e->getMessage();
            }
        }
    }

    // Import loggen
    $db->prepare("INSERT INTO import_log (dateiname, zeilen_gesamt, zeilen_neu, zeilen_aktualisiert) VALUES (?, ?, ?, ?)")
        ->execute([$filename, $gesamt, $neu, $aktualisiert]);

    // ── Snapshot nach Import erstellen ──
    try {
        require_once __DIR__ . '/snapshot.php';
    } catch (Exception $e) {
        // Snapshot-Fehler soll Import nicht blockieren
        error_log('Snapshot nach Import fehlgeschlagen: ' . $e->getMessage());
    }
	
    echo json_encode([
        'success' => true,
        'gesamt' => $gesamt,
        'neu' => $neu,
        'aktualisiert' => $aktualisiert,
        'lieferanten_neu' => $lieferanten_neu,
        'fehler' => $fehler,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Upload-Seite ──
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>📤 Weinkeller Import</title>
<style>
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #1a1a2e; color: #eee; margin: 0; min-height: 100vh; }
    .container { max-width: 700px; margin: 0 auto; padding: 40px 20px; }
    h1 { color: #e94560; }
    .upload-zone { background: #16213e; border: 2px dashed #0f3460; border-radius: 12px; padding: 50px; text-align: center; cursor: pointer; transition: all 0.3s; margin: 24px 0; }
    .upload-zone:hover, .upload-zone.dragover { border-color: #e94560; background: rgba(233,69,96,0.05); }
    .upload-zone input { display: none; }
    .btn { background: #e94560; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 0.9em; text-decoration: none; }
    .btn:hover { opacity: 0.8; }
    .btn-small { background: #0f3460; font-size: 0.8em; padding: 6px 14px; }
    .result { background: #16213e; border-radius: 12px; padding: 20px; margin-top: 16px; display: none; }
    .result.success { border: 1px solid #4ade80; }
    .result.error { border: 1px solid #ef4444; }
    .nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
    .muted { color: #888; font-size: 0.85em; }
</style>
</head><body>
<div class="container">
    <div class="nav">
        <h1>📤 XML Import</h1>
        <div>
            <a href="index.html" class="btn btn-small">← Dashboard</a>
            <form method="POST" style="display:inline"><button name="logout" value="1" class="btn btn-small">🔓 Logout</button></form>
        </div>
    </div>
    <p class="muted">Kellermeister XML-Export hochladen (Backup-ZIP entpacken → XML-Datei wählen)</p>

    <div class="upload-zone" id="zone" onclick="document.getElementById('xml-input').click()">
        <h2>📂 XML-Datei hierher ziehen</h2>
        <p class="muted">oder klicken zum Auswählen</p>
        <input type="file" id="xml-input" accept=".xml" onchange="uploadXML(this.files[0])">
    </div>

    <div class="result" id="result"></div>
</div>

<script>
const zone = document.getElementById('zone');
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
zone.addEventListener('drop', e => { e.preventDefault(); zone.classList.remove('dragover'); uploadXML(e.dataTransfer.files[0]); });

async function uploadXML(file) {
    if (!file) return;
    const res = document.getElementById('result');
    res.style.display = 'block';
    res.className = 'result';
    res.innerHTML = '<p>⏳ Importiere ' + file.name + '...</p>';

    const form = new FormData();
    form.append('xml', file);

    try {
        const resp = await fetch('import.php', { method: 'POST', body: form });
        const data = await resp.json();
        if (data.success) {
            res.className = 'result success';
            res.innerHTML = `
                <h3>✅ Import erfolgreich!</h3>
                <p>📄 <strong>${data.gesamt}</strong> Weine verarbeitet</p>
                <p>🆕 <strong>${data.neu}</strong> neue Weine</p>
                <p>🔄 <strong>${data.aktualisiert}</strong> aktualisiert</p>
                <p>🏪 <strong>${data.lieferanten_neu}</strong> neue Lieferanten</p>
                ${data.fehler.length ? '<p style="color:#ef4444">Fehler: ' + data.fehler.join('<br>') + '</p>' : ''}
                <br><a href="index.html" class="btn">→ Zum Dashboard</a>
            `;
        } else {
            res.className = 'result error';
            res.innerHTML = '<h3>❌ Fehler</h3><p>' + (data.error || JSON.stringify(data)) + '</p>';
        }
    } catch(e) {
        res.className = 'result error';
        res.innerHTML = '<h3>❌ Fehler</h3><p>' + e.message + '</p>';
    }
}
</script>
</body></html>
