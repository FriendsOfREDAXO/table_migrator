# Table_Migrator for REDAXO

# TableMigrator

`TableMigrator` ist eine PHP-Klasse, die entwickelt wurde, um die Migration von Daten zwischen Datenbanktabellen zu erleichtern. Sie bietet eine flexible und erweiterbare Lösung für die Übertragung von Daten aus einer alten Tabellenstruktur in eine neue, mit Unterstützung für verschiedene Arten von Feldmappings und Datenverarbeitung.

## Funktionen

- Einfaches Mapping von Feldern zwischen alter und neuer Tabelle
- Kombinieren mehrerer Felder in ein neues Feld
- Verarbeiten von Feldinhalten während der Migration
- Herstellen von Beziehungen zu verwandten Tabellen
- Automatisches Einfügen von migrierten Daten in die neue Tabelle

## Installation

Fügen Sie die `TableMigrator`-Klasse zu Ihrem REDAXO-Projekt hinzu. Stellen Sie sicher, dass sie im Namespace `klxm\migrator` liegt.

## Verwendung

### Grundlegende Verwendung

```php
use klxm\migrator\TableMigrator;

$migrator = new TableMigrator('old_table', 'new_table');
$migrator->addMapping('new_field', 'old_field')
         ->migrate();
```

### Erweiterte Mappings

```php
// Kombiniertes Mapping
$migrator->addCombinedMapping('full_name', ['first_name', 'last_name'], 
    function($firstName, $lastName) {
        return $firstName . ' ' . $lastName;
    }
);

// Verarbeitetes Mapping
$migrator->addProcessedMapping('description', 'old_description', 
    function($text) {
        return $migrator->truncateAndStripHTML($text, 200);
    }
);

// Verwandtes Mapping
$migrator->addRelatedMapping('category_name', 'category_id', 'rex_categories', 'name', 'Uncategorized');
```

### Durchführen der Migration

Nach dem Einrichten aller Mappings, führen Sie die Migration durch:

```php
$migrator->migrate();
```

## Methoden

- `addMapping($newField, $oldField)`: Einfaches Feldmapping
- `addCombinedMapping($newField, $oldFields, $combineFunction)`: Kombiniert mehrere Felder
- `addProcessedMapping($newField, $oldField, $processFunction)`: Verarbeitet Feldinhalte
- `addRelatedMapping($newField, $oldField, $relatedTable, $relatedField, $defaultValue)`: Stellt Beziehungen her
- `migrate()`: Führt die Migration durch
- `truncateAndStripHTML($text, $maxLength)`: Hilfsmethode zum Kürzen und Bereinigen von HTML

## Beispiele

### Benutzertabelle Migration

```php
$migrator = new TableMigrator('old_users', 'new_users');
$migrator->addMapping('id', 'user_id')
         ->addMapping('email', 'email_address')
         ->addProcessedMapping('password', 'password_hash', function($value) {
             return password_hash($value, PASSWORD_DEFAULT);
         })
         ->addCombinedMapping('full_name', ['first_name', 'last_name'], function($first, $last) {
             return $first . ' ' . $last;
         });
$migrator->migrate();
```

### Produktkatalog Migration

```php
$migrator = new TableMigrator('old_products', 'new_products');
$migrator->addMapping('id', 'product_id')
         ->addMapping('name', 'product_name')
         ->addProcessedMapping('price', 'price_cents', function($value) {
             return $value * 100;
         })
         ->addCombinedMapping('full_sku', ['sku_prefix', 'sku_number'], function($prefix, $number) {
             return $prefix . '-' . str_pad($number, 5, '0', STR_PAD_LEFT);
         });
$migrator->migrate();
```

## Praxis-Beispiel hier aus einer FORcal-Tabelle: 

> Hinweis: die Wiederholungsregeln können nicht übernommen werden.  

```php
// Usage
$migrator = new TableMigratorx('rex_forcal_entries', 'rex_yformcalendar');
$migrator->addMapping('name', 'name_1')
    ->addProcessedMapping('description', 'text_1', function($text) use ($migrator) {
        return $migrator->truncateAndStripHTML($text);
    })
    ->addRelatedMapping('location', 'awoloc', 'rex_orte', 'name')

    ->addCombinedMapping('dtstart', ['start_date', 'start_time'], function($date, $time) {
        return $date . ' ' . $time;
    })
    ->addCombinedMapping('dtend', ['end_date', 'end_time'], function($date, $time) {
        return $date . ' ' . $time;
    })
    ->addProcessedMapping('all_day', 'full_time', function($value) {
        return $value === null ? 0 : ($value ? 1 : 0);
    })
    ->addMapping('categories', 'category');
        // Fields that couldn't be directly mapped:
        // ->addMapping('createdate', 'createdate')
        // ->addMapping('updatedate', 'updatedate')
        // ->addMapping('created_by', 'createuser')
        // ->addMapping('updated_by', 'updateuser')
        // ->addMapping('status', 'status')
        // ->addMapping('teaser', 'teaser_1')
        // ->addMapping('image', 'image')
        // ->addMapping('file', 'file')
        // ->addMapping('lang', 'lang_1')
        // ->addMapping('category_id', 'category')
        // ->addMapping('location_id', 'venue')
$migrator->migrate();
?>

```




## Hinweise

- Stellen Sie sicher, dass die Zieltabelle bereits existiert und die korrekten Felder enthält.
- Testen Sie die Migration zuerst in einer Entwicklungsumgebung, bevor Sie sie auf Produktionsdaten anwenden.

## ToDo

- Implementierung von Fehlerbehandlung und Logging
- Hinzufügen von Unterstützung für Batch-Verarbeitung bei großen Datenmengen
- Erweiterung um Funktionen zum Rückgängigmachen von Migrationen

## Beitrag

Beiträge sind willkommen! Bitte erstellen Sie ein Issue oder einen Pull Request auf GitHub.

## Autor

**Friends Of REDAXO**

* http://www.redaxo.org
* https://github.com/FriendsOfREDAXO

**Projektleitung**

[Thomas Skerbis](https://github.com/skerbis)


## Lizenz

MIT





