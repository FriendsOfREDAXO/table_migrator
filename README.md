# TableMigrator for REDAXO

**Version 2.0** – Fluent-API zur Migration von Daten zwischen REDAXO-Datenbanktabellen.

## Features

- Einfaches 1:1-Feldmapping
- Felder kombinieren (mehrere Quellfelder → ein Zielfeld)
- Felder transformieren (Callback-Verarbeitung)
- Fremdschlüssel über YForm-Relations auflösen
- **Neu: Statisches Mapping** – fixer Wert für alle migrierten Zeilen
- **Neu: Chunk-Verarbeitung** – speicherschonende Migration großer Tabellen
- **Neu: Dry-Run-Modus** – Testlauf ohne DB-Schreibzugriff
- Fehler-Tracking mit detailliertem Stats-Array

## Migration von Version 1.x auf 2.x

> **Breaking Change:** `migrate()` gibt seit v2.0 ein `array` zurück statt eine Erfolgsmeldung per `echo` auszugeben.
> Außerdem wurde der Namespace von `klxm\migrator` auf `FriendsOfREDAXO\TableMigrator` geändert.
>
> ```php
> // Alt (1.x)
> use klxm\migrator\TableMigrator;
> $migrator->migrate(); // gab "Migration abgeschlossen!" aus
>
> // Neu (2.x)
> use FriendsOfREDAXO\TableMigrator\TableMigrator;
> $stats = $migrator->migrate(); // gibt ['total' => ..., 'migrated' => ..., ...] zurück
> ```

## Installation

Das AddOn im REDAXO-Installer installieren. Klassen werden automatisch via REDAXO-Autoloader bereitgestellt.

## Verwendung

### Grundlegendes Mapping

```php
use FriendsOfREDAXO\TableMigrator\TableMigrator;

$migrator = new TableMigrator('old_table', 'new_table');
$stats = $migrator
    ->addMapping('id', 'user_id')
    ->addMapping('email', 'email_address')
    ->migrate();

echo "Migriert: {$stats['migrated']} / {$stats['total']}";
```

### Alle Mapping-Typen im Überblick

```php
use FriendsOfREDAXO\TableMigrator\TableMigrator;

$migrator = new TableMigrator('old_table', 'new_table');

// 1:1-Mapping
$migrator->addMapping('new_field', 'old_field');

// Mehrere Felder kombinieren
$migrator->addCombinedMapping('full_name', ['first_name', 'last_name'],
    fn($first, $last) => trim($first . ' ' . $last)
);

// Feldwert transformieren
$migrator->addProcessedMapping('description', 'old_text',
    fn($text) => $migrator->truncateAndStripHTML($text, 300)
);

// Fremdschlüssel über YForm-Dataset auflösen
$migrator->addRelatedMapping('location', 'location_id', 'rex_locations', 'name', 'Unbekannt');

// Fixer Wert für alle Zeilen (NEU in 2.0)
$migrator->addStaticMapping('status', 1);
$migrator->addStaticMapping('migrated_at', date('Y-m-d H:i:s'));
$migrator->addStaticMapping('source', 'migration_2026');

// Wertetabelle (Lookup-Map, NEU in 2.1)
$migrator->addConditionalMapping('status', 'old_status', [
    'active'   => 1,
    'inactive' => 0,
    'pending'  => 2,
], 0); // Fallback-Wert

// Migration starten
$stats = $migrator->migrate();
```

### Dry-Run-Modus (neu in 2.2)

`dryRun()` führt alle Mappings und Transformationen vollständig aus – erkennt also Fehler in
der Konfiguration – schreibt aber keinen einzigen INSERT in die Zieltabelle.
Perfekt zum Validieren vor dem echten Produktionslauf.

```php
$migrator = new TableMigrator('old_table', 'new_table');
$migrator
    ->addMapping('title', 'headline')
    ->addConditionalMapping('status', 'state', ['active' => 1, 'draft' => 0], 0)
    ->addStaticMapping('migrated_at', date('Y-m-d H:i:s'));

// Testlauf – DB bleibt unberührt
$stats = $migrator->dryRun()->migrate();

if ($stats['skipped'] > 0) {
    foreach ($stats['errors'] as $err) {
        dump($err['error'], $err['row']);
    }
} else {
    // Alles OK – jetzt scharf migrieren
    $stats = $migrator->dryRun(false)->migrate();
}
```

### Conditional Mapping / Lookup-Tabelle (neu in 2.1)

`addConditionalMapping()` übersetzt Quellwerte anhand einer Lookup-Map in Zielwerte.
Ideal wenn Enum-Werte, Status-Codes oder numerische IDs sich zwischen Alt- und Neusystem unterscheiden.
Ein optionaler Fallback-Wert greift, wenn kein Eintrag in der Map passt.

```php
$migrator = new TableMigrator('old_articles', 'new_articles');
$migrator
    ->addMapping('title', 'headline')
    // Status-Strings → Integer
    ->addConditionalMapping('status', 'publish_state', [
        'published' => 1,
        'draft'     => 0,
        'archived'  => 2,
    ], 0) // Fallback: 0
    // Alte Kategorie-IDs → neue Kategorie-IDs
    ->addConditionalMapping('category_id', 'cat', [
        10 => 1,
        11 => 1,
        20 => 3,
        21 => 3,
    ], null);

$stats = $migrator->migrate();
```

### Statisches Mapping (neu in 2.0)

`addStaticMapping()` setzt für **jede migrierte Zeile** einen fixen Wert – unabhängig von der Quelltabelle.
Typische Anwendungsfälle: Status-Flags, Erstellungszeitstempel, Migrations-Kennzeichen.

```php
$migrator = new TableMigrator('old_products', 'new_products');
$migrator
    ->addMapping('name', 'product_name')
    ->addMapping('price', 'price_cents')
    ->addStaticMapping('status', 1)                             // Alle auf "aktiv"
    ->addStaticMapping('created_at', date('Y-m-d H:i:s'))      // Erstellungsdatum
    ->addStaticMapping('data_source', 'legacy_import_2026');    // Herkunftskennzeichen

$stats = $migrator->migrate();
```

### Chunk-Verarbeitung für große Tabellen (neu in 2.0)

`migrateChunked()` liest die Quelltabelle in konfigurierbaren Blöcken – ideal bei Tabellen mit
Tausenden von Zeilen, bei denen ein vollständiges `SELECT *` den PHP-Speicher erschöpfen würde.
Fehler einzelner Zeilen brechen die gesamte Migration nicht ab.

```php
$migrator = new TableMigrator('rex_huge_legacy_table', 'rex_new_table');
$migrator
    ->addMapping('title', 'name')
    ->addProcessedMapping('body', 'content', fn($t) => strip_tags($t))
    ->addStaticMapping('status', 1);

// 200 Zeilen pro Chunk (Standard: 500)
$stats = $migrator->migrateChunked(200);

echo "Gesamt:    {$stats['total']}\n";
echo "Migriert:  {$stats['migrated']}\n";
echo "Fehler:    {$stats['skipped']}\n";

foreach ($stats['errors'] as $err) {
    echo "Fehler bei ID " . ($err['row']['id'] ?? '?') . ": " . $err['error'] . "\n";
}
```

### Rückgabewert von `migrate()` und `migrateChunked()`

Beide Methoden geben ein assoziatives Array zurück:

| Schlüssel  | Typ    | Bedeutung                                           |
|------------|--------|-----------------------------------------------------|
| `total`    | int    | Gesamtzahl der gefundenen Quellzeilen               |
| `migrated` | int    | Erfolgreich eingefügte Zeilen                       |
| `skipped`  | int    | Übersprungene Zeilen (Fehler)                       |
| `errors`   | array  | Liste von `['row' => [...], 'error' => '...']`      |

## Methoden-Referenz

| Methode | Beschreibung |
|---------|-------------|
| `addMapping(string $new, string $old)` | Einfaches 1:1-Feldmapping |
| `addCombinedMapping(string $new, array $oldFields, callable $fn)` | Mehrere Felder zusammenführen |
| `addProcessedMapping(string $new, string $old, callable $fn)` | Feldwert transformieren |
| `addRelatedMapping(string $new, string $old, string $table, string $field, string $default)` | YForm-Relation auflösen |
| `addStaticMapping(string $new, mixed $value)` | Fixen Wert für alle Zeilen setzen |
| `addConditionalMapping(string $new, string $old, array $valueMap, mixed $default)` | Wert per Lookup-Tabelle übersetzen |
| `dryRun(bool $on = true): self` | Dry-Run-Modus aktivieren (kein DB-Insert) |
| `migrate(): array` | Migration in einem Schritt (kleine Tabellen) |
| `migrateChunked(int $chunkSize = 500): array` | Chunk-Verarbeitung (große Tabellen) |
| `truncateAndStripHTML(string $text, int $maxLength = 256): string` | HTML entfernen und kürzen |

## Praxis-Beispiel: FORcal → YForm-Calendar

> Hinweis: Wiederholungsregeln können nicht automatisch übernommen werden.

```php
use FriendsOfREDAXO\TableMigrator\TableMigrator;

$migrator = new TableMigrator('rex_forcal_entries', 'rex_yformcalendar');
$migrator
    ->addMapping('name', 'name_1')
    ->addProcessedMapping('description', 'text_1',
        fn($text) => $migrator->truncateAndStripHTML($text)
    )
    ->addRelatedMapping('location', 'awoloc', 'rex_orte', 'name')
    ->addCombinedMapping('dtstart', ['start_date', 'start_time'],
        fn($date, $time) => $date . ' ' . $time
    )
    ->addCombinedMapping('dtend', ['end_date', 'end_time'],
        fn($date, $time) => $date . ' ' . $time
    )
    ->addProcessedMapping('all_day', 'full_time',
        fn($v) => $v === null ? 0 : ($v ? 1 : 0)
    )
    ->addMapping('categories', 'category')
    ->addStaticMapping('migrated_at', date('Y-m-d H:i:s'))
    ->addStaticMapping('status', 1);

$stats = $migrator->migrateChunked(100);
echo "Migration abgeschlossen: {$stats['migrated']}/{$stats['total']} Einträge.";
```

## Hinweise

- Die Zieltabelle muss bereits existieren und alle Zielfelder enthalten.
- Migration zuerst in einer Entwicklungsumgebung testen.
- Fehler-Debugging: `foreach ($stats['errors'] as $e) { error_log($e['error']); }`
- Namespace seit v2.0: `FriendsOfREDAXO\TableMigrator`

## Changelog

### 2.2.0

- **Neu:** `dryRun()` – Testlauf ohne DB-Schreibzugriff; Mappings und Transformationen werden vollständig ausgeführt

### 2.1.0

- **Neu:** `addConditionalMapping()` – Wertübersetzung per Lookup-Tabelle (Enum/Status/ID-Mapping)

### 2.0.0

- Namespace von `klxm\migrator` auf `FriendsOfREDAXO\TableMigrator` umgestellt
- **Neu:** `addStaticMapping()` – fixer Wert für alle Zeilen
- **Neu:** `migrateChunked()` – speicherschonende Chunk-Verarbeitung mit Fehler-Tracking
- `migrate()` gibt jetzt ein Stats-Array zurück statt `echo` (Breaking Change)
- Vollständige PHP 8.1+ Typisierung und PHPDoc
- `error_log()` durch `rex_logger::logException()` ersetzt

## Beitrag

Beiträge sind willkommen! Bitte erstellen Sie ein Issue oder einen Pull Request auf GitHub.

## Autor

**Friends Of REDAXO**

- https://www.redaxo.org
- https://github.com/FriendsOfREDAXO

**Projektleitung:** [Thomas Skerbis](https://github.com/skerbis)

## Lizenz

MIT





