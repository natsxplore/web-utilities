<?php

namespace App\Services\Conversions;

use App\Services\Conversions\Contracts\TableConversion;
use RuntimeException;

class ConversionRegistry
{
    /** @var array<string, TableConversion> */
    protected array $conversions = [];

    public function __construct(
        CompanyFileConversion $company,
        SystemParameterConversion $systemParameter,
        BranchFileConversion $branch,
        UserFileConversion $userFile,
        CurrencyFileConversion $currency,
    ) {
        foreach ([$company, $systemParameter, $branch, $userFile, $currency] as $conversion) {
            $this->conversions[$conversion->key()] = $conversion;
        }
    }

    public function get(string $key): TableConversion
    {
        return $this->conversions[$key]
            ?? throw new RuntimeException("Unknown conversion \"{$key}\".");
    }
}
