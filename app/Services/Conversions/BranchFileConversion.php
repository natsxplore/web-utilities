<?php

namespace App\Services\Conversions;

class BranchFileConversion extends ChunkedTableConversion
{
    public function key(): string
    {
        return 'branch';
    }

    protected function sourceTable(): string
    {
        return 'branchfile';
    }

    protected function targetTable(): string
    {
        return 'branch_file';
    }

    protected function orderColumn(): string
    {
        return 'branchcde';
    }

    protected function mapRow(object $old): array
    {
        return [
            'branch_code' => $old->branchcde,
            'branch_description' => $old->branchdsc,
        ];
    }
}
