<?php

namespace App\Services;

use App\DataTransfer\RemoteDatabaseConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DataConversionService
{
    public function __construct(
        protected DynamicConnectionManager $connections,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{rows: int, tables: list<string>, message: string}
     */
    public function run(RemoteDatabaseConfig $source, RemoteDatabaseConfig $target, array $input): array
    {
        $this->connections->assertReachable($source, 'source', 'Source');
        $this->connections->assertReachable($target, 'target', 'Target');

        $sourceDb = DB::connection($this->connections->register($source, 'source'));
        $targetDb = DB::connection($this->connections->register($target, 'target'));

        $companyFile = (bool) ($input['company_file'] ?? false);
        $systemParameter = (bool) ($input['system_parameter'] ?? false);
        $branchFile = (bool) ($input['branch'] ?? false);
        $userFile = (bool) ($input['user_file'] ?? false);
        $currencyFile = (bool) ($input['currency'] ?? false);
        $taxCode = (bool) ($input['tax_code'] ?? false);
        $itemClassification = (bool) ($input['item_classification'] ?? false);
        $itemSubClass = (bool) ($input['item_sub_class'] ?? false);
        $warehouse = (bool) ($input['warehouse'] ?? false);
        $dineType = (bool) ($input['dine_type'] ?? false);
        $cardType = (bool) ($input['card_type'] ?? false);
        $memc = (bool) ($input['memc'] ?? false);
        $otherPayments = (bool) ($input['other_payments'] ?? false);
        $item = (bool) ($input['item'] ?? false);
        $discount = (bool) ($input['discount'] ?? false);
        $specialRequest = (bool) ($input['special_request'] ?? false);
        $freeReason = (bool) ($input['free_reason'] ?? false);
        $voidReason = (bool) ($input['void_reason'] ?? false);
        $cashInOutReason = (bool) ($input['cash_in_out_reason'] ?? false);
        $priceList = (bool) ($input['price_list'] ?? false);
        $inventoryTransaction = (bool) ($input['inventory_transaction'] ?? false);
        $physicalCount = (bool) ($input['physical_count'] ?? false);
        $companyName = trim((string) ($input['company_name'] ?? ''));

        $totalRows = 0;
        $transferredTables = [];
        $now = now();


            #region Company File Conversion
            if ($companyFile) {
                // companyName from $input

                if ($companyName === '') {
                    throw new RuntimeException('Company ID is required for Company file conversion.');
                }

                $oldTableName = 'companyfile';
                $newTableName = 'companyfile';
                $chunkSize = 500;
                $companyRows = 0;
                $payload = [];

                $targetDb->table($newTableName)->truncate();

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {

                    $payload[] = [
                        'company_id' => $companyName,
                        'company_description' => $old->comdsc,
                        'company_address1' => $old->companyadd1,
                        'company_address2' => $old->companyadd2,
                        'branch_id' => $old->brhcde,
                        'address1' => $old->address1,
                        'address2' => $old->address2,
                        'address3' => $old->address3,
                        'machine_number' => $old->machineno,
                        'serial_number' => $old->serialno,
                        'tax_payer' => $old->taxpayer,
                        'tin_number' => $old->tin,
                        'company_tin' => $old->companytin,
                        'pos_terminal_number' => $old->postrmno,
                        'tenant_id' => $old->tenantid,
                        'company_zip_code' => $old->companyzipcode,
                        'email' => $old->email,
                        'website' => $old->website,
                        'fax_number' => $old->faxnum,
                        'telephone_number' => $old->telno,
                        'is_non_vat' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $companyRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $companyRows += count($payload);
                }

                $totalRows += $companyRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$companyRows} row(s))";
            }
            #endregion

            #region System Parameter Conversion
            if ($systemParameter) {
                $oldTableName = 'syspar';
                $oldTableName2 = 'syspar2';
                $newTableName = 'sys_setup';

                $old = $sourceDb->table($oldTableName)->orderBy('recid')->first();
                $old2 = $sourceDb->table($oldTableName2)->orderBy('recid')->first();

                if ($old === null) {
                    throw new RuntimeException('No syspar record found in source database.');
                }

                if ($old2 === null) {
                    throw new RuntimeException('No syspar2 record found in source database.');
                }

                $updateData = [
                    // syspar → sys_setup
                    'prompt_days' => $old->promptdays,
                    'prompt_price' => $old->promptprice,
                    'increment_type' => $old->incrementtype,
                    'round_2_qty' => $old->round2qty,
                    'chk_item_search' => $old->chkitemsearch,
                    'chk_cus_search' => $old->chkcussearch,
                    'chk_sup_search' => $old->chksupsearch,
                    'visible_barcode' => $old->chkbarcode,
                    'console_file_sync' => $old->consofilesync,
                    'critical_level_type' => $old->crilvltyp,
                    'chk_inv_print' => $old->chkinvprint,
                    
                    // syspar2 → sys_setup
                    'vat_id_basis' => $old2->vatcdebasis,
                    'check_non_vat' => $old2->chknonvat,
                    'visible_sales_price_list' => $old2->salprccde,
                    'check_inactive' => $old2->chkinactive,
                    'disable_inventory_link' => $old2->disableinvlink,
                    'price_inventory_in' => $old2->prcinvin,
                    'price_inventory_out' => $old2->prcinvout,
                    'negative_inventory_balance' => 1,
                    // 'negative_inventory_balance' => $old2->neginvbal,
                    'inventory_warehouse_header' => $old2->invwarheader,
                    'inventory_price_decimal' => $old2->invprcdec,
                    'inventory_max_item' => $old2->invmaxitem,
                    'document_lock_type' => $old2->doclocktype,
                    'updated_at' => $now,
                ];

                $sysSetupRows = $targetDb->table($newTableName)->update($updateData);

                if ($sysSetupRows === 0) {
                    throw new RuntimeException('No sys_setup record found in target database to update.');
                }

                $totalRows += $sysSetupRows;
                $transferredTables[] = "{$oldTableName}, {$oldTableName2} → {$newTableName} ({$sysSetupRows} row(s) updated)";
            }
            #endregion

            #region Branch File Conversion
            if ($branchFile) {
                $oldTableName = 'branchfile';
                $newTableName = 'mf_branch';
                $chunkSize = 500;
                $branchRows = 0;
                $dateLockingRows = 0;
                $payload = [];
                $dateLockingPayload = [];

                $targetDb->table('date_locking')->delete();

                $excludedUsers = [
                    'LSTVSTDUSER0001', 
                    'LSTVSTDUSER0002', 
                    'LSTVSTDUSER0003', 
                    'lstv', 
                    'LSTV-API-USER'
                ];

                $targetDb->table('users')
                         ->whereIn('user_id', $excludedUsers)
                         ->update(['last_used_branch_id' => null]);

                $targetDb->table('trn_inventory_transaction_file1')->delete();
                $targetDb->table('trn_inventory_transaction_file2')->delete();

                $targetDb->table('trn_physical_count_file1')->delete();
                $targetDb->table('trn_physical_count_file2')->delete();
                $targetDb->table('trn_physical_count_file3')->delete();
                $targetDb->table('trn_physical_count_file31')->delete();

                $targetDb->table('mf_price_code_file1')->delete();
                $targetDb->table('mf_price_code_file2')->delete();
                $targetDb->table('mf_price_code_file3')->delete();
                $targetDb->table('mf_price_code_file4')->delete();

                $targetDb->table('document_file_number_series')
                         ->whereNotIn('branch_id', ['ALL', 'LSTVDEFAULTBRANCH1'])
                         ->delete();

                $targetDb->table('mf_inventory_transactiontype_file2')
                         ->whereNotIn('branch_id', ['ALL', 'LSTVDEFAULTBRANCH1'])
                         ->delete();

                $targetDb->table('users')
                         ->whereNotIn('user_id', $excludedUsers)
                         ->delete();

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'branch_id' => $old->brhcde,
                        'branch_description' => $old->brhdsc,
                        'branch_prefix' => $old->prefix !== null && $old->prefix !== '' ? $old->prefix : 'MAIN',
                        'business1' => $old->business1,
                        'business2' => $old->business2,
                        'business3' => $old->business3,
                        'address1' => $old->address1,
                        'address2' => $old->address2,
                        'address3' => $old->address3,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $dateLockingPayload[] = [
                        'branch_id' => $old->brhcde,
                        'sales_date_lock1' => $old->saldatelock1,
                        'sales_date_lock2' => $old->saldatelock2,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $targetDb->table('date_locking')->insert($dateLockingPayload);
                        $branchRows += count($payload);
                        $dateLockingRows += count($dateLockingPayload);
                        $payload = [];
                        $dateLockingPayload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $targetDb->table('date_locking')->insert($dateLockingPayload);
                    $branchRows += count($payload);
                    $dateLockingRows += count($dateLockingPayload);
                }

                $allBranch = $targetDb->table($newTableName)
                    ->where('branch_id', 'ALL')
                    ->first();

                if ($allBranch !== null) {
                    $sysSetupUpdated = $targetDb->table('sys_setup')->update([
                        'default_branch_id' => $allBranch->branch_id,
                        'updated_at' => $now,
                    ]);
                }

                $totalRows += $branchRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$branchRows} row(s))";
                $transferredTables[] = "branchfile → date_locking ({$dateLockingRows} row(s))";
            }
            #endregion

            #region User File Conversion
            if ($userFile) {
                $oldTableName = 'userfile';
                $newTableName = 'user';
                $chunkSize = 500;
                $userRows = 0;
                $payload = [];
            }
            #endregion

            #region Currency Conversion
            if ($currencyFile) {
                $oldTableName = 'currencyfile';
                $newTableName = 'mf_currencies';
                $chunkSize = 500;
                $currencyRows = 0;
                $payload = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'currency_id' => $old->curcde,
                        'currency_code' => $old->curcde,
                        'currency_description' => $old->curdsc,
                        'currency_rate' => $old->currte,
                        'currency_symbol' => $old->cursym,
                        'currency_date' => $old->curdte,
                        'currency_words' => $old->curwords,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $currencyRows += count($payload);
                        $payload = [];
                    }
                }
                
                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $currencyRows += count($payload);
                }

                $phpCurrency = $targetDb->table($newTableName)
                    ->where(function ($query) {
                        $query->where('currency_id', 'PHP')
                            ->orWhere('currency_code', 'PHP');
                    })
                    ->first();

                if ($phpCurrency !== null) {
                    $baseCurrency = $phpCurrency->currency_id ?: $phpCurrency->currency_code;

                    $sysSetupUpdated = $targetDb->table('sys_setup')->update([
                        'base_currency' => $baseCurrency,
                        'updated_at' => $now,
                    ]);
                }

                $totalRows += $currencyRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$currencyRows} row(s))";
            }
            #endregion

            #region Tax Code Conversion
            if ($taxCode) {
                $oldTableName = 'taxcodefile';
                $newTableName = 'mf_vat_codes';
                $chunkSize = 500;
                $taxCodeRows = 0;
                $payload = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'tax_id' => $old->taxcde,
                        'tax_code' => $old->taxcde,
                        'tax_percent' => $old->taxper,
                        'debit_account_id' => $old->debactcde,
                        'credit_account_id' => $old->creactcde,
                        'return_credit_account_id' => $old->retactcde,
                        'tax_type' => $old->taxtyp,
                        'gl_account_id' => $old->actcde,
                        'consignment_account_id' => $old->actcdecon,
                        'consignment_gl_department_id' => $old->gldepcdecon,
                        'gl_department_id' => $old->gldepcde,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $taxCodeRows += count($payload);
                        $payload = [];
                    }
                }
                
                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $taxCodeRows += count($payload);
                }

                $totalRows += $taxCodeRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$taxCodeRows} row(s))";
            }
            #endregion

            #region Item Classification Conversion
            if ($itemClassification) {
                $oldTableName = 'itemclassfile';
                $newTableName = 'mf_itemclassifications';
                $chunkSize = 500;
                $itemClassificationRows = 0;
                $payload = [];
                $seenClassificationIds = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenClassificationIds[$old->itmclacde])) {
                        continue;
                    }

                    $seenClassificationIds[$old->itmclacde] = true;

                    $payload[] = [
                        'item_classification_id' => $old->itmclacde,
                        'item_classification_description' => $old->itmcladsc,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $itemClassificationRows += count($payload);
                        $payload = [];
                    }
                }
                
                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $itemClassificationRows += count($payload);
                }

                $totalRows += $itemClassificationRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$itemClassificationRows} row(s))";
            }
            #endregion

            #region Item Sub Class Conversion
            if ($itemSubClass) {
                $oldTableName = 'itemsubclassfile';
                $newTableName = 'mf_itemsubclassifications';
                $chunkSize = 500;
                $itemSubClassRows = 0;
                $payload = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'item_subclassification_id' => $old->itemsubclasscde,
                        'item_subclassification_description' => $old->itemsubclassdsc,
                        'prev_item_subclassification_id' => $old->prev_itemsubclasscde,
                        'item_classification_id' => $old->itmclacde,
                        'location_id' => $old->locationcde,
                        'last_modified' => $old->lastmod,
                        'hide_subclass' => $old->hide_subclass,
                        'subclass_image' => $old->subclassimage,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $itemSubClassRows += count($payload);
                        $payload = [];
                    }
                }
                
                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $itemSubClassRows += count($payload);
                }

                $totalRows += $itemSubClassRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$itemSubClassRows} row(s))";
            }
            #endregion

            #region Warehouse Conversion
            if ($warehouse) {
                $oldTableName = 'warehousefile';
                $newTableName = 'mf_warehouses';
                $chunkSize = 500;
                $warehouseRows = 0;
                $payload = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'warehouse_id' => $old->warcde,
                        'warehouse_description' => $old->wardsc,
                        'branch_id' => $old->brhcde,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'include_critical_level' => $old->inccrilvl,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $warehouseRows += count($payload);
                        $payload = [];
                    }
                }
                
                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $warehouseRows += count($payload);
                }

                $allWarehouse = $targetDb->table($newTableName)
                    ->where('warehouse_id', 'ALL')
                    ->first();

                if ($allWarehouse !== null) {
                    $targetDb->table('sys_setup')->update([
                        'critical_level_warehouse_id' => $allWarehouse->warehouse_id,
                        'default_warehouse_id' => $allWarehouse->warehouse_id,
                        'updated_at' => $now,
                    ]);
                }

                $totalRows += $warehouseRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$warehouseRows} row(s))";
            }
            #endregion

            #region Dine Type Conversion
            if ($dineType) {
                $oldTableName = 'postypefile';
                $newTableName = 'mf_dine_type_file';
                $chunkSize = 500;
                $dineTypeRows = 0;
                $payload = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'dine_type_id' => $old->postypcde,
                        'dine_type' => $old->postypdsc,
                        'order_type' => $old->ordertyp,
                        'is_modified' => $old->ismodified,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $dineTypeRows += count($payload);
                        $payload = [];
                    }
                }
                
                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $dineTypeRows += count($payload);
                }

                $totalRows += $dineTypeRows;
                $transferredTables[] = "{$oldTableName} → {$newTableName} ({$dineTypeRows} row(s))";
            }
            #endregion
            
        if ($totalRows === 0) {
            throw new RuntimeException('No rows were transferred.');
        }

        return [
            'rows' => $totalRows,
            'tables' => $transferredTables,
            'message' => "Transferred {$totalRows} row(s) from {$source->database} to {$target->database}.",
        ];
    }
}
