<?php

namespace App\Services\Conversions;

class CurrencyFileConversion extends ChunkedTableConversion
{
    public function key(): string
    {
        return 'currency';
    }

    protected function sourceTable(): string
    {
        return 'currencyfile';
    }

    protected function targetTable(): string
    {
        return 'currency_file';
    }

    protected function orderColumn(): string
    {
        return 'curcde';
    }

    protected function mapRow(object $old): array
    {
        return [
            'currency_code' => $old->curcde,
            'currency_description' => $old->curdsc,
        ];
    }
}
