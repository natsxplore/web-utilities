<?php

namespace App\Services\Conversions;

use App\Services\Conversions\Contracts\TableConversion;
use Illuminate\Database\Connection;

abstract class ChunkedTableConversion implements TableConversion
{
    private const CHUNK_SIZE = 500;

    abstract protected function sourceTable(): string;

    abstract protected function targetTable(): string;

    abstract protected function orderColumn(): string;

    /**
     * Old row → new row. Declare one line per field.
     *
     * @return array<string, mixed>
     */
    abstract protected function mapRow(object $old): array;

    public function run(Connection $source, Connection $target): array
    {
        $rows = 0;
        $sourceTable = $this->sourceTable();
        $targetTable = $this->targetTable();

        $source->table($sourceTable)
            ->orderBy($this->orderColumn())
            ->chunk(self::CHUNK_SIZE, function ($chunk) use ($target, $targetTable, &$rows) {
                $payload = [];

                foreach ($chunk as $old) {
                    $payload[] = $this->mapRow($old);
                }

                $target->table($targetTable)->insert($payload);
                $rows += count($payload);
            });

        return [
            'rows' => $rows,
            'source_table' => $sourceTable,
            'target_table' => $targetTable,
        ];
    }
}
