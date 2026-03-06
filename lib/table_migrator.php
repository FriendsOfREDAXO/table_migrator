<?php

namespace FriendsOfREDAXO\TableMigrator;

/**
 * TableMigrator – migriert Daten zwischen zwei REDAXO-Datenbanktabellen.
 *
 * Features:
 * - Einfaches, kombiniertes, verarbeitetes und relationales Feldmapping
 * - Statisches Mapping (fixer Wert für alle Zeilen)
 * - Wertübersetzung per Lookup-Tabelle (Conditional Mapping)
 * - Chunk-Verarbeitung für speicherschonende Migration großer Tabellen
 * - Dry-Run-Modus: Testlauf ohne DB-Schreibzugriff
 * - Fehler-Tracking mit detailliertem Stats-Array
 */
class TableMigrator
{
    /** @var array<string, mixed> */
    private array $mappings = [];

    /** @var array<string, mixed> */
    private array $staticMappings = [];

    private bool $isDryRun = false;

    private \rex_sql $sql;

    public function __construct(private string $oldTable, private string $newTable)
    {
        $this->sql = \rex_sql::factory();
    }

    /**
     * Aktiviert oder deaktiviert den Dry-Run-Modus.
     *
     * Im Dry-Run werden alle Mappings und Transformationen vollständig ausgeführt
     * (Fehler werden erkannt), aber kein einziger INSERT in die Zieltabelle geschrieben.
     * Ideal zum Testen vor dem echten Produktionslauf.
     *
     * $stats = $migrator->dryRun()->migrate();
     */
    public function dryRun(bool $on = true): self
    {
        $this->isDryRun = $on;
        return $this;
    }

    /**
     * Einfaches 1:1-Feldmapping.
     */
    public function addMapping(string $newField, string $oldField): self
    {
        $this->mappings[$newField] = $oldField;
        return $this;
    }

    /**
     * Mehrere Quellfelder werden per Callback zu einem Zielfeld kombiniert.
     *
     * @param string[] $oldFields
     */
    public function addCombinedMapping(string $newField, array $oldFields, callable $combineFunction): self
    {
        $this->mappings[$newField] = ['fields' => $oldFields, 'function' => $combineFunction];
        return $this;
    }

    /**
     * Quellfeld wird per Callback transformiert und in das Zielfeld geschrieben.
     */
    public function addProcessedMapping(string $newField, string $oldField, callable $processFunction): self
    {
        $this->mappings[$newField] = ['field' => $oldField, 'process' => $processFunction];
        return $this;
    }

    /**
     * Löst eine Fremdschlüssel-ID über eine verwandte Tabelle auf (YForm-Dataset).
     */
    public function addRelatedMapping(
        string $newField,
        string $oldField,
        string $relatedTable,
        string $relatedField,
        string $defaultValue = '',
    ): self {
        $this->mappings[$newField] = [
            'type' => 'related',
            'field' => $oldField,
            'relatedTable' => $relatedTable,
            'relatedField' => $relatedField,
            'defaultValue' => $defaultValue,
        ];
        return $this;
    }

    /**
     * Setzt für alle migrierten Zeilen einen fixen Wert – unabhängig von der Quelltabelle.
     * Nützlich für Status-Felder, Timestamps oder Migrationskennzeichen.
     *
     * Beispiel: $migrator->addStaticMapping('status', 1)
     *           $migrator->addStaticMapping('migrated_at', date('Y-m-d H:i:s'))
     */
    public function addStaticMapping(string $newField, mixed $value): self
    {
        $this->staticMappings[$newField] = $value;
        return $this;
    }

    /**
     * Mappt Quellwerte anhand einer Lookup-Tabelle auf Zielwerte.
     * Ideal wenn Enum-Werte, Status-Codes oder IDs sich zwischen Alt- und Neusystem unterscheiden.
     *
     * Beispiel:
     * $migrator->addConditionalMapping('status', 'old_status', [
     *     'active'   => 1,
     *     'inactive' => 0,
     *     'pending'  => 2,
     * ], 0); // Fallback-Wert wenn kein Match
     *
     * @param array<string|int, mixed> $valueMap
     */
    public function addConditionalMapping(
        string $newField,
        string $oldField,
        array $valueMap,
        mixed $default = null,
    ): self {
        $this->mappings[$newField] = [
            'type' => 'conditional',
            'field' => $oldField,
            'valueMap' => $valueMap,
            'default' => $default,
        ];
        return $this;
    }

    /**
     * Führt die Migration in einem Schritt durch (geeignet für kleine Tabellen).
     *
     * @return array{total: int, migrated: int, skipped: int, errors: list<array{row: array<string,mixed>, error: string}>}
     */
    public function migrate(): array
    {
        /** @var list<array<string, mixed>> $data */
        $data = $this->sql->getArray('SELECT * FROM ' . $this->oldTable);
        return $this->processRows($data);
    }

    /**
     * Verarbeitet die Quelltabelle in Chunks – ideal für große Datenmengen,
     * die sonst den PHP-Speicher überlasten würden.
     *
     * @return array{total: int, migrated: int, skipped: int, errors: list<array{row: array<string,mixed>, error: string}>}
     */
    public function migrateChunked(int $chunkSize = 500): array
    {
        $stats = ['total' => 0, 'migrated' => 0, 'skipped' => 0, 'errors' => []];

        $countSql = \rex_sql::factory();
        $total = (int) $countSql->getArray('SELECT COUNT(*) AS cnt FROM ' . $this->oldTable)[0]['cnt'];
        $stats['total'] = $total;

        $offset = 0;
        while ($offset < $total) {
            /** @var list<array<string, mixed>> $chunk */
            $chunk = $this->sql->getArray(
                'SELECT * FROM ' . $this->oldTable . ' LIMIT ' . $chunkSize . ' OFFSET ' . $offset,
            );

            if ([] === $chunk) {
                break;
            }

            $chunkStats = $this->processRows($chunk);
            $stats['migrated'] += $chunkStats['migrated'];
            $stats['skipped'] += $chunkStats['skipped'];
            /** @var list<array{row: array<string,mixed>, error: string}> $merged */
            $merged = array_merge($stats['errors'], $chunkStats['errors']);
            $stats['errors'] = $merged;

            $offset += $chunkSize;
        }

        return $stats;
    }

    /**
     * Verarbeitet eine Liste von Zeilen und gibt ein Stats-Array zurück.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, migrated: int, skipped: int, errors: list<array{row: array<string,mixed>, error: string}>}
     */
    private function processRows(array $rows): array
    {
        $stats = ['total' => count($rows), 'migrated' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($rows as $row) {
            try {
                $newData = $this->buildRowData($row);
                if (!$this->isDryRun) {
                    $this->insertIntoNewTable($newData);
                }
                ++$stats['migrated'];
            } catch (\Throwable $e) {
                ++$stats['skipped'];
                $stats['errors'][] = ['row' => $row, 'error' => $e->getMessage()];
            }
        }

        return $stats;
    }

    /**
     * Baut den Datensatz für die Zieltabelle aus Mappings + statischen Werten auf.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function buildRowData(array $row): array
    {
        $newData = [];

        foreach ($this->mappings as $newField => $mapping) {
            if (is_array($mapping)) {
                if (isset($mapping['fields'])) {
                    // Combined mapping
                    $values = array_map(static fn(string $field) => $row[$field], $mapping['fields']);
                    $newData[$newField] = ($mapping['function'])(...$values);
                } elseif (isset($mapping['field'], $mapping['process'])) {
                    // Processed mapping
                    $newData[$newField] = ($mapping['process'])($row[$mapping['field']]);
                } elseif (isset($mapping['type']) && 'related' === $mapping['type']) {
                    // Related mapping
                    $newData[$newField] = $this->getRelatedValue(
                        $row[$mapping['field']],
                        $mapping['relatedTable'],
                        $mapping['relatedField'],
                        $mapping['defaultValue'],
                    );
                } elseif (isset($mapping['type']) && 'conditional' === $mapping['type']) {
                    // Conditional (lookup) mapping
                    $sourceValue = $row[$mapping['field']];
                    $newData[$newField] = array_key_exists($sourceValue, $mapping['valueMap'])
                        ? $mapping['valueMap'][$sourceValue]
                        : $mapping['default'];
                }
            } else {
                $newData[$newField] = $row[$mapping];
            }
        }

        // Statische Werte überschreiben / ergänzen
        foreach ($this->staticMappings as $newField => $value) {
            $newData[$newField] = $value;
        }

        return $newData;
    }

    /**
     * Löst eine YForm-Dataset-Relation auf.
     */
    private function getRelatedValue(mixed $id, string $relatedTable, string $relatedField, string $defaultValue = 'Unbekannt'): string
    {
        if (null === $id || '' === $id) {
            return $defaultValue;
        }

        $id = (int) $id;

        try {
            $relatedObject = \rex_yform_manager_dataset::get($id, $relatedTable);
            if ($relatedObject) {
                $value = $relatedObject->getValue($relatedField);
                return (null !== $value && '' !== $value) ? (string) $value : $defaultValue;
            }
            return $defaultValue;
        } catch (\Exception $e) {
            \rex_logger::logException($e);
            return $defaultValue;
        }
    }

    /**
     * Fügt einen Datensatz in die Zieltabelle ein.
     *
     * @param array<string, mixed> $data
     */
    private function insertIntoNewTable(array $data): void
    {
        $fields = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $this->sql->setQuery("INSERT INTO {$this->newTable} ($fields) VALUES ($placeholders)", $data);
    }

    /**
     * Entfernt HTML-Tags, dekodiert Entities und kürzt auf Satz- oder Wortgrenze.
     */
    public function truncateAndStripHTML(string $text, int $maxLength = 256): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = substr($text, 0, $maxLength);

        // Letztes Satzende im Ausschnitt suchen
        $lastSentenceEnd = 0;
        preg_match_all('/[.!?](?=\s|$)/u', $truncated, $matches, PREG_OFFSET_CAPTURE);

        if (!empty($matches[0])) {
            $lastSentenceEnd = $matches[0][count($matches[0]) - 1][1] + 1;
        }

        if ($lastSentenceEnd > 0) {
            return trim(substr($text, 0, $lastSentenceEnd));
        }

        $lastSpace = strrpos($truncated, ' ');
        return $lastSpace !== false ? trim(substr($text, 0, $lastSpace)) : $truncated;
    }
}

