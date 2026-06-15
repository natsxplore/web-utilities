<?php

namespace App\Services\Conversions;

class CompanyFileConversion extends ChunkedTableConversion
{
    public function key(): string
    {
        return 'company';
    }

    protected function sourceTable(): string
    {
        return 'companyfile';
    }

    protected function targetTable(): string
    {
        return 'company_file';
    }

    protected function orderColumn(): string
    {
        return 'companycde';
    }

    protected function mapRow(object $old): array
    {
        return [
            'company_code' => $old->companycde,
            'company_description' => $old->companydsc,
        ];
    }
}
