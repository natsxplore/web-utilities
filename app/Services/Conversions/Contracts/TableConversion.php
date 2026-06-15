<?php

namespace App\Services\Conversions\Contracts;

use Illuminate\Database\Connection;

interface TableConversion
{
    public function key(): string;

    /**
     * @return array{rows: int, source_table: string, target_table: string}
     */
    public function run(Connection $source, Connection $target): array;
}
