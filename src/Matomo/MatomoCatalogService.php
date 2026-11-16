<?php

namespace DDA\MatomoConnector\Matomo;

use DDA\MatomoConnector\Support\Config;
use PDO;

final class MatomoCatalogService
{
    public function __construct(private Config $config)
    {
    }

    /** @return array<string, mixed> */
    public function loadCatalog(): array
    {
        $pdo = $this->pdo();
        $schema = MatomoSchema::fromConfig($this->config);
        $schema->assertRequiredTablesReadable($pdo);

        return [
            'tablePrefix' => $schema->prefix(),
            'sites' => $this->loadSites($pdo, $schema),
            'availableColumns' => $this->loadAvailableColumns($pdo, $schema),
            'goals' => $this->loadGoals($pdo, $schema),
            'customDimensions' => $this->loadCustomDimensions($pdo, $schema),
        ];
    }

    private function pdo(): PDO
    {
        $matomo = $this->config->matomo();
        $host = (string) ($matomo['host'] ?? '127.0.0.1');
        $port = (int) ($matomo['port'] ?? 3306);
        $database = (string) ($matomo['database'] ?? '');
        $charset = (string) ($matomo['charset'] ?? 'utf8mb4');
        $username = (string) ($matomo['username'] ?? '');
        $password = (string) ($matomo['password'] ?? '');

        if ($database === '' || $username === '') {
            throw new \RuntimeException('Matomo database and username must be configured.');
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @return list<array{id:int,title:string,mainUrl:string,timezone:string}> */
    private function loadSites(PDO $pdo, MatomoSchema $schema): array
    {
        $statement = $pdo->query('SELECT idsite, name, main_url, timezone FROM ' . $schema->quotedTable('site') . ' ORDER BY name ASC, idsite ASC');
        $rows = $statement === false ? [] : $statement->fetchAll();

        $sites = [];
        foreach ($rows as $row) {
            $id = (int) ($row['idsite'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $title = trim((string) ($row['name'] ?? ''));
            $sites[] = [
                'id' => $id,
                'title' => $title !== '' ? $title : 'Website ' . $id,
                'mainUrl' => trim((string) ($row['main_url'] ?? '')),
                'timezone' => trim((string) ($row['timezone'] ?? '')),
            ];
        }

        return $sites;
    }

    /**
     * Goals belong to one website. An installation without the goals table
     * reports none.
     *
     * @return list<array{siteId:int,id:int,name:string,allowMultiple:bool}>
     */
    private function loadGoals(PDO $pdo, MatomoSchema $schema): array
    {
        try {
            $statement = $pdo->query(
                'SELECT idsite, idgoal, name, allow_multiple FROM ' . $schema->quotedTable('goal')
                . ' WHERE deleted = 0 ORDER BY idsite ASC, idgoal ASC'
            );
        } catch (\Throwable) {
            return [];
        }

        $goals = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $siteId = (int) ($row['idsite'] ?? 0);
            $id = (int) ($row['idgoal'] ?? 0);
            if ($siteId <= 0 || $id <= 0) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $goals[] = [
                'siteId' => $siteId,
                'id' => $id,
                'name' => $name !== '' ? $name : 'Goal ' . $id,
                'allowMultiple' => (int) ($row['allow_multiple'] ?? 0) === 1,
            ];
        }

        return $goals;
    }

    /**
     * Active custom dimensions per website. The index names the value column
     * custom_dimension_<index> of the visit or the action.
     *
     * @return list<array{siteId:int,id:int,index:int,scope:string,name:string}>
     */
    private function loadCustomDimensions(PDO $pdo, MatomoSchema $schema): array
    {
        try {
            $statement = $pdo->query(
                'SELECT idsite, idcustomdimension, `index`, scope, name FROM ' . $schema->quotedTable('custom_dimensions')
                . ' WHERE active = 1 ORDER BY idsite ASC, scope ASC, `index` ASC'
            );
        } catch (\Throwable) {
            return [];
        }

        $dimensions = [];
        foreach ($statement === false ? [] : $statement->fetchAll() as $row) {
            $siteId = (int) ($row['idsite'] ?? 0);
            $index = (int) ($row['index'] ?? 0);
            $scope = strtolower(trim((string) ($row['scope'] ?? '')));
            if ($siteId <= 0 || $index <= 0 || !in_array($scope, ['visit', 'action'], true)) {
                continue;
            }
            $id = (int) ($row['idcustomdimension'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));
            $dimensions[] = [
                'siteId' => $siteId,
                'id' => $id,
                'index' => $index,
                'scope' => $scope,
                'name' => $name !== '' ? $name : 'Custom Dimension ' . $id,
            ];
        }

        return $dimensions;
    }

    /** @return array<string, list<string>> */
    private function loadAvailableColumns(PDO $pdo, MatomoSchema $schema): array
    {
        $matomo = $this->config->matomo();
        $database = (string) ($matomo['database'] ?? '');
        $catalogTables = $schema->catalogTables();
        $physicalTables = array_keys($catalogTables);
        $canonicalTablesByLowerName = [];
        foreach ($catalogTables as $physicalTable => $canonicalTable) {
            $canonicalTablesByLowerName[strtolower($physicalTable)] = $canonicalTable;
        }
        $placeholders = implode(',', array_fill(0, count($physicalTables), '?'));
        $sql = "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)";
        $statement = $pdo->prepare($sql);
        $statement->execute([$database, ...$physicalTables]);

        $availableColumns = [];
        foreach ($statement->fetchAll() as $row) {
            $physicalTable = strtolower((string) ($row['TABLE_NAME'] ?? ''));
            $table = $canonicalTablesByLowerName[$physicalTable] ?? '';
            $column = strtolower((string) ($row['COLUMN_NAME'] ?? ''));
            if ($table === '' || $column === '') {
                continue;
            }
            $availableColumns[$table] ??= [];
            if (!in_array($column, $availableColumns[$table], true)) {
                $availableColumns[$table][] = $column;
            }
        }

        return $availableColumns;
    }
}
