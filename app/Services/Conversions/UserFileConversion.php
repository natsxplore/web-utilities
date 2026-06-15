<?php

namespace App\Services\Conversions;

class UserFileConversion extends ChunkedTableConversion
{
    public function key(): string
    {
        return 'user_file';
    }

    protected function sourceTable(): string
    {
        return 'userfile';
    }

    protected function targetTable(): string
    {
        return 'user_file';
    }

    protected function orderColumn(): string
    {
        return 'usrcde';
    }

    protected function mapRow(object $old): array
    {
        return [
            'user_code' => $old->usrcde,
            'user_name' => $old->usrdsc,
        ];
    }
}
