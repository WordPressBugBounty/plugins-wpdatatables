<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

namespace WPDataTables\Services\Ai;

use Throwable;

/**
 * Fetches a privacy-safe column schema for a table, for injection into AI prompts.
 *
 * Lite reads schema and sample rows through the classic controllers
 * ({@see \WDTConfigController} and {@see \WPDataTable}) instead of the premium
 * table services.
 *
 * Only column names + types (and whether a column is filterable) ever leave the
 * site — never the base query SQL (`content`), connection credentials, or more
 * than a capped sample of cell values.
 *
 * @package WPDataTables\Services\Ai
 */
class TableMetaService
{
    /** Max rows included in an AI chart-suggestion sample. */
    const SAMPLE_MAX_ROWS = 5;

    /** Max columns included in an AI chart-suggestion sample. */
    const SAMPLE_MAX_COLS = 8;

    /** Max characters per sample cell value. */
    const SAMPLE_CELL_MAX_LEN = 80;

    /**
     * @param int $tableId
     * @return array{type: string, columns: array<int, array{name: string, type: string, filterable: bool}>}|null
     *         Null when the table or its columns cannot be loaded.
     */
    public function getTableMeta(int $tableId): ?array
    {
        if ($tableId <= 0 || !class_exists('WDTConfigController')) {
            return null;
        }

        try {
            $table = \WDTConfigController::loadTableFromDB($tableId);
        } catch (Throwable $e) {
            return null;
        }

        if (!$table) {
            return null;
        }

        $columns = \WDTConfigController::loadColumnsFromDB($tableId);
        if (!$columns) {
            return null;
        }

        $mapped = [];
        foreach ($columns as $column) {
            $mapped[] = [
                'name'       => isset($column->orig_header) ? (string) $column->orig_header : '',
                'type'       => isset($column->column_type) ? (string) $column->column_type : 'string',
                'filterable' => !empty($column->filter_type),
            ];
        }

        return [
            'type'    => isset($table->table_type) ? (string) $table->table_type : '',
            'columns' => $mapped,
        ];
    }

    /**
     * Privacy-safe data sample for chart suggestions: at most 5 rows × 8 cols.
     *
     * Returns `[]` when the table cannot be loaded or has no rows — the AI can
     * still recommend from schema alone.
     *
     * @param int $tableId
     * @return array<int, array<string, scalar|null>>
     */
    public function getSampleData(int $tableId): array
    {
        if ($tableId <= 0) {
            return [];
        }

        $meta = $this->getTableMeta($tableId);
        if ($meta === null || empty($meta['columns'])) {
            return [];
        }

        $columnNames = [];
        foreach (array_slice($meta['columns'], 0, self::SAMPLE_MAX_COLS) as $column) {
            if (!empty($column['name'])) {
                $columnNames[] = (string) $column['name'];
            }
        }

        if ($columnNames === []) {
            return [];
        }

        if (!class_exists('WPDataTable')) {
            return [];
        }

        try {
            $wpDataTable = \WPDataTable::loadWpDataTable($tableId);
        } catch (Throwable $e) {
            return [];
        }

        if (!$wpDataTable || !method_exists($wpDataTable, 'getDataRows')) {
            return [];
        }

        $rows = $wpDataTable->getDataRows();
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $sample = [];
        foreach (array_slice($rows, 0, self::SAMPLE_MAX_ROWS) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out = [];
            foreach ($columnNames as $name) {
                $out[$name] = $this->sanitizeSampleCell($row[$name] ?? null);
            }
            $sample[] = $out;
        }

        return $sample;
    }

    /**
     * @param mixed $value
     * @return scalar|null
     */
    private function sanitizeSampleCell($value)
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = (string) $value;
        if (strlen($text) > self::SAMPLE_CELL_MAX_LEN) {
            $text = substr($text, 0, self::SAMPLE_CELL_MAX_LEN) . '…';
        }

        return $text;
    }
}
