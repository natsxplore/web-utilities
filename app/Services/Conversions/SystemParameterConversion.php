<?php

namespace App\Services\Conversions;

class SystemParameterConversion extends ChunkedTableConversion
{
    public function key(): string
    {
        return 'system_parameter';
    }

    protected function sourceTable(): string
    {
        return 'sysparfile';
    }

    protected function targetTable(): string
    {
        return 'system_parameter';
    }

    protected function orderColumn(): string
    {
        return 'parcde';
    }

    protected function mapRow(object $old): array
    {
        return [
            'parameter_code' => $old->parcde,
            'parameter_description' => $old->pardsc,
            'parameter_value' => $old->parval,
        ];
    }
}
