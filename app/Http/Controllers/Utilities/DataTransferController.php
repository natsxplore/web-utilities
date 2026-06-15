<?php

namespace App\Http\Controllers\Utilities;

use App\DataTransfer\RemoteDatabaseConfig;
use App\Http\Controllers\Controller;
use App\Services\DynamicConnectionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class DataTransferController extends Controller
{
    protected array $conversionOptions = [
        'company_file' => 'company',
        'system_parameter' => 'system_parameter',
        'branch' => 'branch',
        'user_file' => 'user_file',
        'currency' => 'currency',
        'tax_code' => 'tax_code',
        'item_classification' => 'item_classification',
        'item_sub_class' => 'item_sub_class',
        'warehouse' => 'warehouse',
        'dine_type' => 'dine_type',
        'card_type' => 'card_type',
        'memc' => 'memc',
        'other_payments' => 'other_payments',
        'item' => 'item',
        'discount' => 'discount',
        'special_request' => 'special_request',
        'free_reason' => 'free_reason',
        'void_reason' => 'void_reason',
        'cash_in_out_reason' => 'cash_in_out_reason',
        'price_list' => 'price_list',
        'inventory_transaction' => 'inventory_transaction',
        'physical_count' => 'physical_count',
    ];

    public function __construct(
        protected DynamicConnectionManager $connections,
    ) {}

    public function index(): View
    {
        return view('utilities.transfer');
    }

    public function testConnection(Request $request): JsonResponse
    {
        return $this->respondJson(function () use ($request) {
            $validated = $this->validateConnection($request);
            $config = RemoteDatabaseConfig::fromConnectionAndDatabase($validated, RemoteDatabaseConfig::systemDatabaseFor($validated['driver']));
            $this->connections->test($config, 'source');

            return [
                'ok' => true,
                'message' => "Connected to {$config->host}:{$config->port} as {$config->username}.",
                'databases' => $this->connections->databases($config),
            ];
        });
    }

    public function runConversion(Request $request): JsonResponse
    {
        try {
            $companyFile = $request->boolean('company_file');
            $systemParameter = $request->boolean('system_parameter');
            $branchFile = $request->boolean('branch');
            $userFile = $request->boolean('user_file');
            $currencyFile = $request->boolean('currency');
            $taxCode = $request->boolean('tax_code');
            $itemClassification = $request->boolean('item_classification');
            $itemSubClass = $request->boolean('item_sub_class');
            $warehouse = $request->boolean('warehouse');
            $dineType = $request->boolean('dine_type');
            $cardType = $request->boolean('card_type');
            $memc = $request->boolean('memc');
            $otherPayments = $request->boolean('other_payments');
            $item = $request->boolean('item');
            $discount = $request->boolean('discount');
            $specialRequest = $request->boolean('special_request');
            $freeReason = $request->boolean('free_reason');
            $voidReason = $request->boolean('void_reason');
            $cashInOutReason = $request->boolean('cash_in_out_reason');
            $priceList = $request->boolean('price_list');
            $inventoryTransaction = $request->boolean('inventory_transaction');
            $physicalCount = $request->boolean('physical_count');

            [$source, $target] = $this->resolveDatabases($request);

            $this->connections->assertReachable($source, 'source', 'Source');
            $this->connections->assertReachable($target, 'target', 'Target');

            $sourceDb = DB::connection($this->connections->register($source, 'source'));
            $targetDb = DB::connection($this->connections->register($target, 'target'));

            $totalRows = 0;
            $transferredTables = [];
            $conversionNotes = [];
            $now = now();

            #region Company File Conversion
            if ($companyFile) {
                $companyName = trim((string) $request->input('company_name', ''));

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
                    'inventory_max_item' => 0,
                    // 'inventory_max_item' => $old2->invmaxitem,
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
                // do not delete ALL and MAIN branch branch_description
                $targetDb->table($newTableName)
                         ->where('branch_description', '!=', 'ALL')
                         ->where('branch_description', '!=', 'MAIN')
                         ->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'branch_id' => $old->brhcde,
                        'branch_description' => $old->brhdsc,
                        'branch_prefix' => $this->generateBranchPrefix(
                            $this->optionalRowValue($old, 'prefix'),
                            $old->brhdsc,
                            $old->brhcde,
                        ),
                        'business1' => $this->optionalRowValue($old, 'business1'),
                        'business2' => $this->optionalRowValue($old, 'business2'),
                        'business3' => $this->optionalRowValue($old, 'business3'),
                        'address1' => $this->optionalRowValue($old, 'address1'),
                        'address2' => $this->optionalRowValue($old, 'address2'),
                        'address3' => $this->optionalRowValue($old, 'address3'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $dateLockingPayload[] = [
                        'branch_id' => $old->brhcde,
                        'sales_date_lock1' => $this->optionalRowValue($old, 'saldatelock1'),
                        'sales_date_lock2' => $this->optionalRowValue($old, 'saldatelock2'),
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
                $skippedClassificationRows = 0;
                $payload = [];
                $seenClassificationIds = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenClassificationIds[$old->itmclacde])) {
                        $skippedClassificationRows++;
                        $note = "Skipped duplicate item classification \"{$old->itmclacde}\" ({$old->itmcladsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'item_classification',
                            'item_classification_id' => $old->itmclacde,
                            'item_classification_description' => $old->itmcladsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

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
                $classificationSummary = "{$oldTableName} → {$newTableName} ({$itemClassificationRows} row(s))";
                if ($skippedClassificationRows > 0) {
                    $classificationSummary .= ", {$skippedClassificationRows} duplicate(s) skipped";
                }
                $transferredTables[] = $classificationSummary;
            }
            #endregion

            #region Item Sub Class Conversion
            if ($itemSubClass) {
                $oldTableName = 'itemsubclassfile';
                $newTableName = 'mf_itemsubclassifications';
                $chunkSize = 500;
                $itemSubClassRows = 0;
                $skippedSubClassRows = 0;
                $payload = [];
                $seenSubClassificationIds = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenSubClassificationIds[$old->itemsubclasscde])) {
                        $skippedSubClassRows++;
                        $note = "Skipped duplicate item sub classification \"{$old->itemsubclasscde}\" ({$old->itemsubclassdsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'item_sub_class',
                            'item_subclassification_id' => $old->itemsubclasscde,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenSubClassificationIds[$old->itemsubclasscde] = true;

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
                $subClassSummary = "{$oldTableName} → {$newTableName} ({$itemSubClassRows} row(s))";
                if ($skippedSubClassRows > 0) {
                    $subClassSummary .= ", {$skippedSubClassRows} duplicate(s) skipped";
                }
                $transferredTables[] = $subClassSummary;
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
                $targetDb->table($newTableName)->where('is_default', '!=', 1)->delete();
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
                $skippedDineTypeRows = 0;
                $payload = [];
                $seenDineTypeIds = [];

                // temporary turn off foreign key checks
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenDineTypeIds[$old->postypcde])) {
                        $skippedDineTypeRows++;
                        $note = "Skipped duplicate dine type \"{$old->postypcde}\" ({$old->postypdsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'dine_type',
                            'dine_type_id' => $old->postypcde,
                            'dine_type' => $old->postypdsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenDineTypeIds[$old->postypcde] = true;

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
                $dineTypeSummary = "{$oldTableName} → {$newTableName} ({$dineTypeRows} row(s))";
                if ($skippedDineTypeRows > 0) {
                    $dineTypeSummary .= ", {$skippedDineTypeRows} duplicate(s) skipped";
                }
                $transferredTables[] = $dineTypeSummary;
            }
            #endregion

            #region Card Type Conversion
            if ($cardType) {
                $oldTableName = 'cardtypefile';
                $newTableName = 'mf_cardtypes';
                $chunkSize = 500;
                $cardTypeRows = 0;
                $skippedCardTypeRows = 0;
                $payload = [];
                $seenCardTypeIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenCardTypeIds[$old->cardtype])) {
                        $skippedCardTypeRows++;
                        $note = "Skipped duplicate card type \"{$old->cardtype}\"; kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'card_type',
                            'card_types_id' => $old->cardtype,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenCardTypeIds[$old->cardtype] = true;

                    $payload[] = [
                        'card_types_id' => $old->cardtype,
                        'card_types_description' => $old->cardtypedsc !== null && $old->cardtypedsc !== '' ? $old->cardtypedsc : $old->cardtype,
                        'old_card_types_id' => $old->oldcardtype,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $cardTypeRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $cardTypeRows += count($payload);
                }

                $totalRows += $cardTypeRows;
                $cardTypeSummary = "{$oldTableName} → {$newTableName} ({$cardTypeRows} row(s))";
                if ($skippedCardTypeRows > 0) {
                    $cardTypeSummary .= ", {$skippedCardTypeRows} duplicate(s) skipped";
                }
                $transferredTables[] = $cardTypeSummary;
            }
            #endregion

            #region MEMC Conversion
            if ($memc) {
                $oldTableName = 'memcfile';
                $newTableName = 'mf_memcfile';
                $chunkSize = 500;
                $memcRows = 0;
                $skippedMemcRows = 0;
                $payload = [];
                $seenMemcIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenMemcIds[$old->code])) {
                        $skippedMemcRows++;
                        $note = "Skipped duplicate MEMC \"{$old->code}\" ({$old->codedsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'memc',
                            'memc_id' => $old->code,
                            'memc_description' => $old->codedsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenMemcIds[$old->code] = true;

                    $payload[] = [
                        'memc_id' => $old->code,
                        'memc_description' => $old->codedsc,
                        'prev_memc_id' => $old->prev_code,
                        'value' => $old->value,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $memcRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $memcRows += count($payload);
                }

                $totalRows += $memcRows;
                $memcSummary = "{$oldTableName} → {$newTableName} ({$memcRows} row(s))";
                if ($skippedMemcRows > 0) {
                    $memcSummary .= ", {$skippedMemcRows} duplicate(s) skipped";
                }
                $transferredTables[] = $memcSummary;
            }
            #endregion

            #region Other Payments Conversion
            if ($otherPayments) {
                $oldTableName = 'paymentfile';
                $newTableName = 'mf_other_payments_file';
                $chunkSize = 500;
                $otherPaymentsRows = 0;
                $skippedOtherPaymentsRows = 0;
                $payload = [];
                $seenPaymentTypeIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenPaymentTypeIds[$old->paytyp])) {
                        $skippedOtherPaymentsRows++;
                        $note = "Skipped duplicate other payment \"{$old->paytyp}\" ({$old->paytypdsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'other_payments',
                            'payment_type_id' => $old->paytyp,
                            'payment_type_description' => $old->paytypdsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenPaymentTypeIds[$old->paytyp] = true;

                    $payload[] = [
                        'payment_type_id' => $old->paytyp,
                        'payment_type_description' => $old->paytypdsc !== null && $old->paytypdsc !== '' ? $old->paytypdsc : $old->paytyp,
                        'is_exported' => $old->isexported,
                        'is_modified' => $old->ismodified,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $otherPaymentsRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $otherPaymentsRows += count($payload);
                }

                $totalRows += $otherPaymentsRows;
                $otherPaymentsSummary = "{$oldTableName} → {$newTableName} ({$otherPaymentsRows} row(s))";
                if ($skippedOtherPaymentsRows > 0) {
                    $otherPaymentsSummary .= ", {$skippedOtherPaymentsRows} duplicate(s) skipped";
                }
                $transferredTables[] = $otherPaymentsSummary;
            }
            #endregion

            #region Item Conversion
            if ($item) {
                $oldTableName = 'itemfile';
                $oldUnitTableName = 'itemunitfile';
                $newTableName = 'mf_items';
                $newUnitTableName = 'mf_item_units';
                $chunkSize = 500;
                $itemRows = 0;
                $itemUnitRows = 0;
                $nullifiedItemReferences = 0;
                $payload = [];
                $unitPayload = [];
                $insertedItemIds = [];

                $validClassificationIds = $targetDb->table('mf_itemclassifications')
                    ->pluck('item_classification_id')
                    ->flip()
                    ->all();

                $validSubClassificationIds = $targetDb->table('mf_itemsubclassifications')
                    ->pluck('item_subclassification_id')
                    ->flip()
                    ->all();

                $itemCodesWithUnits = $sourceDb->table($oldUnitTableName)
                    ->distinct()
                    ->pluck('itmcde')
                    ->flip()
                    ->all();

                $itemMultiUmFlags = $sourceDb->table($oldTableName)
                    ->pluck('multium', 'itmcde')
                    ->all();

                $defaultUnitOfMeasureId = $targetDb->table('mf_unit_of_measures')
                    ->where('is_default', 1)
                    ->value('unit_of_measure_id');

                $unitOfMeasureIdsByCode = [];
                foreach ($targetDb->table('mf_unit_of_measures')->get(['unit_of_measure_id', 'unit_of_measure']) as $unitOfMeasureRow) {
                    $unitOfMeasureIdsByCode[strtoupper(trim((string) $unitOfMeasureRow->unit_of_measure))] = $unitOfMeasureRow->unit_of_measure_id;
                }

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newUnitTableName)->delete();
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $classificationId = trim((string) ($old->itmclacde ?? ''));
                    $subClassificationId = trim((string) ($old->itemsubclasscde ?? ''));

                    if ($classificationId === '' || ! isset($validClassificationIds[$classificationId])) {
                        if ($classificationId !== '') {
                            $nullifiedItemReferences++;
                            $note = "Set item_classification_id to null for item \"{$old->itmcde}\" ({$old->itmdsc}): \"{$classificationId}\" not found in mf_itemclassifications.";
                            $conversionNotes[] = $note;
                            Log::warning($note, [
                                'conversion' => 'item',
                                'item_id' => $old->itmcde,
                                'item_classification_id' => $classificationId,
                                'source_database' => $source->database,
                                'target_database' => $target->database,
                            ]);
                        }
                        $classificationId = null;
                    }

                    if ($subClassificationId !== '' && ! isset($validSubClassificationIds[$subClassificationId])) {
                        $nullifiedItemReferences++;
                        $note = "Set item_sub_classification_id to null for item \"{$old->itmcde}\" ({$old->itmdsc}): \"{$subClassificationId}\" not found in mf_itemsubclassifications.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'item',
                            'item_id' => $old->itmcde,
                            'item_sub_classification_id' => $subClassificationId,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);
                        $subClassificationId = null;
                    } elseif ($subClassificationId === '') {
                        $subClassificationId = null;
                    }

                    $insertedItemIds[$old->itmcde] = true;

                    $payload[] = [
                        'item_id' => $old->itmcde,
                        'item_description' => $old->itmdsc,
                        'unit_of_measure' => $old->untmea,
                        'unit_of_measure_id' => $this->resolveUnitOfMeasureId($old->untmea, $defaultUnitOfMeasureId, $unitOfMeasureIdsByCode),
                        'reorder_level' => $old->crilvl,
                        'unit_cost' => $old->untcst,
                        'unit_price' => $old->untprc,
                        'multiple_unit_of_measure' => $old->multium,
                        'sales_unit_of_measure' => $old->salum !== null && $old->salum !== '' ? $old->salum : $old->untmea,
                        'sales_return_unit_of_measure' => $old->srtum !== null && $old->srtum !== '' ? $old->srtum : ($old->salum !== null && $old->salum !== '' ? $old->salum : $old->untmea),
                        'purchase_unit_of_measure' => $old->recum !== null && $old->recum !== '' ? $old->recum : $old->untmea,
                        'purchase_return_unit_of_measure' => $old->prtum !== null && $old->prtum !== '' ? $old->prtum : ($old->recum !== null && $old->recum !== '' ? $old->recum : $old->untmea),
                        'inventory_unit_of_measure' => $old->invum !== null && $old->invum !== '' ? $old->invum : $old->untmea,
                        'barcode_number' => $old->barcde,
                        'required_batch_number' => $old->reqbatchnum,
                        'package' => $old->package,
                        'item_type' => $old->itmtyp,
                        'item_classification_id' => $classificationId,
                        'item_sub_classification_id' => $subClassificationId,
                        'cgs_account_id' => $old->cgsactcde,
                        'sales_discount_account_id' => $old->saldisact,
                        'purchase_discount_account_id' => $old->purdisact,
                        'sales_account_id' => $old->salactcde,
                        'inventory_account_id' => $old->invactcde,
                        'sales_return_account_id' => $old->srtactcde,
                        'tax_id' => $old->taxcde,
                        'purchase_return_account_id' => $old->prtactcde,
                        'purchase_account_id' => $old->puractcde,
                        'purchase_tax_id' => $old->purtaxcde,
                        'sales_ewt_id' => $old->salewtcde,
                        'purchase_ewt_id' => $old->purewtcde,
                        'sales_evat_id' => $old->salevatcde,
                        'purchase_evat_id' => $old->purevatcde,
                        'sales_currency_id' => $old->salcur,
                        'purchase_currency_id' => $old->purcur,
                        'item_picture1' => $old->itmpic,
                        'item_picture2' => $old->itmpic2,
                        'item_picture3' => $old->itmpic3,
                        'is_combo_meal' => $old->chkcombo,
                        'memc_id' => $old->memc,
                        'foreign_description' => $old->itmdscforeign,
                        'non_trade' => $old->chknontrd,
                        'gl_department_id' => $old->gldepcde,
                        'item_number' => $old->itmnum,
                        'field01' => $old->field01,
                        'field02' => $old->field02,
                        'field03' => $old->field03,
                        'field04' => $old->field04,
                        'field05' => $old->field05,
                        'field06' => $old->field06,
                        'field07' => $old->field07,
                        'field08' => $old->field08,
                        'field09' => $old->field09,
                        'field10' => $old->field10,
                        'field11' => $old->field11,
                        'field12' => $old->field12,
                        'field13' => $old->field13,
                        'field14' => $old->field14,
                        'field15' => $old->field15,
                        'field16' => $old->field16,
                        'field17' => $old->field17,
                        'field18' => $old->field18,
                        'field19' => $old->field19,
                        'field20' => $old->field20,
                        'string_quantity' => $old->strqty,
                        'item_balance' => $old->itmbal,
                        'inactive' => $old->inactive,
                        'senior_citizen_pwd_discount' => $old->scpwddis,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'item_description_short' => $old->itmdscshort,
                        'item_include_in' => 'SAL,REC,INV,FG,RM',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (! isset($itemCodesWithUnits[$old->itmcde])) {
                        $unitPayload[] = [
                            'item_id' => $old->itmcde,
                            'conversion' => 1,
                            'unit_of_measure' => $old->untmea,
                            'unit_of_measure_id' => $this->resolveUnitOfMeasureId($old->untmea, $defaultUnitOfMeasureId, $unitOfMeasureIdsByCode),
                            'unit_cost' => $old->untcst,
                            'gross_price' => $old->untprc,
                            'unit_price' => $old->untprc,
                            'discount_amount' => null,
                            'sales_currency_id' => $old->salcur,
                            'purchase_currency_id' => $old->purcur,
                            'barcode_number' => $old->barcde,
                            'minimum_selling_price' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $itemRows += count($payload);
                        $payload = [];
                    }

                    if (count($unitPayload) >= $chunkSize) {
                        $targetDb->table($newUnitTableName)->insert($unitPayload);
                        $itemUnitRows += count($unitPayload);
                        $unitPayload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $itemRows += count($payload);
                }

                if ($unitPayload !== []) {
                    $targetDb->table($newUnitTableName)->insert($unitPayload);
                    $itemUnitRows += count($unitPayload);
                }

                $unitPayload = [];

                foreach ($sourceDb->table($oldUnitTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (! isset($insertedItemIds[$old->itmcde])) {
                        continue;
                    }

                    $isMultiUm = ($itemMultiUmFlags[$old->itmcde] ?? null) == 1;

                    $unitPayload[] = [
                        'item_id' => $old->itmcde,
                        'conversion' => $isMultiUm
                            ? ($old->conver !== null && $old->conver !== '' ? $old->conver : 1)
                            : 1,
                        'unit_of_measure' => $old->untmea,
                        'unit_of_measure_id' => $this->resolveUnitOfMeasureId($old->untmea, $defaultUnitOfMeasureId, $unitOfMeasureIdsByCode),
                        'unit_cost' => $old->untcst,
                        'gross_price' => $old->groprc,
                        'unit_price' => $old->untprc,
                        'discount_amount' => $old->disamt,
                        'sales_currency_id' => $old->salcur,
                        'purchase_currency_id' => $old->purcur,
                        'barcode_number' => $old->barcde,
                        'minimum_selling_price' => $old->minselprc,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($unitPayload) >= $chunkSize) {
                        $targetDb->table($newUnitTableName)->insert($unitPayload);
                        $itemUnitRows += count($unitPayload);
                        $unitPayload = [];
                    }
                }

                if ($unitPayload !== []) {
                    $targetDb->table($newUnitTableName)->insert($unitPayload);
                    $itemUnitRows += count($unitPayload);
                }

                $totalRows += $itemRows + $itemUnitRows;
                $itemSummary = "{$oldTableName} → {$newTableName} ({$itemRows} row(s))";
                if ($nullifiedItemReferences > 0) {
                    $itemSummary .= ", {$nullifiedItemReferences} invalid reference(s) set to null";
                }
                $transferredTables[] = $itemSummary;
                $transferredTables[] = "{$oldUnitTableName} → {$newUnitTableName} ({$itemUnitRows} row(s))";
            }
            #endregion

            #region Discount Conversion
            if ($discount) {
                $oldTableName = 'discountfile';
                $newTableName = 'mf_discounts';
                $chunkSize = 500;
                $discountRows = 0;
                $skippedDiscountRows = 0;
                $payload = [];
                $seenDiscountIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenDiscountIds[$old->discde])) {
                        $skippedDiscountRows++;
                        $note = "Skipped duplicate discount \"{$old->discde}\" ({$old->disdsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'discount',
                            'discount_id' => $old->discde,
                            'discount_description' => $old->disdsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenDiscountIds[$old->discde] = true;

                    $payload[] = [
                        'discount_amount' => strtolower($old->distyp) == 'amount' ? $old->disamt : 0,
                        'discount_id' => $old->discde,
                        'discount_code' => $old->discde,
                        'discount_description' => $old->disdsc,
                        'discount_type' => $old->distyp,
                        'discount_percent' => strtolower($old->distyp) == 'percent' ? $old->disper : 0,
                        'exempt_vat' => $old->exemptvat,
                        'less_vat_discount' => $old->nolessvat,
                        'with_service_charge_discount' => $old->scharge,
                        'government_discount' => $old->govdisc,
                        'online_deals' => $old->online_deals,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $discountRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $discountRows += count($payload);
                }

                $totalRows += $discountRows;
                $discountSummary = "{$oldTableName} → {$newTableName} ({$discountRows} row(s))";
                if ($skippedDiscountRows > 0) {
                    $discountSummary .= ", {$skippedDiscountRows} duplicate(s) skipped";
                }
                $transferredTables[] = $discountSummary;
            }
            #endregion

            #region Special Request Conversion
            if ($specialRequest) {
                $oldTableName = 'modifierfile';
                $oldTableName2 = 'modifiergroupfile';
                $newTableName = 'mf_special_request_file';
                $newTableName2 = 'mf_special_request_file2';
                $chunkSize = 500;
                $specialRequestRows = 0;
                $specialRequestGroupRows = 0;
                $skippedSpecialRequestRows = 0;
                $skippedSpecialRequestGroupRows = 0;
                $payload = [];
                $groupPayload = [];
                $seenSpecialRequestIds = [];
                $seenSpecialRequestGroups = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName2)->delete();
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenSpecialRequestIds[$old->modcde])) {
                        $skippedSpecialRequestRows++;
                        $note = "Skipped duplicate special request \"{$old->modcde}\"; kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'special_request',
                            'special_request_id' => $old->modcde,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenSpecialRequestIds[$old->modcde] = true;

                    $payload[] = [
                        'special_request_id' => $old->modcde,
                        'special_request_description' => $old->modcde,
                        'special_request_price' => $old->modprc,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $specialRequestRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $specialRequestRows += count($payload);
                }

                foreach ($sourceDb->table($oldTableName2)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $groupKey = "{$old->modcde}|{$old->modgrpcde}";

                    if (isset($seenSpecialRequestGroups[$groupKey])) {
                        $skippedSpecialRequestGroupRows++;
                        $note = "Skipped duplicate special request group \"{$old->modcde}\" / \"{$old->modgrpcde}\"; kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'special_request',
                            'special_request_id' => $old->modcde,
                            'item_subclassification_id' => $old->modgrpcde,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenSpecialRequestGroups[$groupKey] = true;

                    $groupPayload[] = [
                        'special_request_id' => $old->modcde,
                        'item_subclassification_id' => $old->modgrpcde,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($groupPayload) >= $chunkSize) {
                        $targetDb->table($newTableName2)->insert($groupPayload);
                        $specialRequestGroupRows += count($groupPayload);
                        $groupPayload = [];
                    }
                }

                if ($groupPayload !== []) {
                    $targetDb->table($newTableName2)->insert($groupPayload);
                    $specialRequestGroupRows += count($groupPayload);
                }

                $totalRows += $specialRequestRows + $specialRequestGroupRows;

                $specialRequestSummary = "{$oldTableName} → {$newTableName} ({$specialRequestRows} row(s))";
                if ($skippedSpecialRequestRows > 0) {
                    $specialRequestSummary .= ", {$skippedSpecialRequestRows} duplicate(s) skipped";
                }
                $transferredTables[] = $specialRequestSummary;

                $specialRequestGroupSummary = "{$oldTableName2} → {$newTableName2} ({$specialRequestGroupRows} row(s))";
                if ($skippedSpecialRequestGroupRows > 0) {
                    $specialRequestGroupSummary .= ", {$skippedSpecialRequestGroupRows} duplicate(s) skipped";
                }
                $transferredTables[] = $specialRequestGroupSummary;
            }
            #endregion

            #region Free Reason Conversion
            if ($freeReason) {
                $oldTableName = 'freereasonfile';
                $newTableName = 'mf_free_reasons';
                $chunkSize = 500;
                $freeReasonRows = 0;
                $skippedFreeReasonRows = 0;
                $payload = [];
                $seenFreeReasonIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenFreeReasonIds[$old->freereason])) {
                        $skippedFreeReasonRows++;
                        $note = "Skipped duplicate free reason \"{$old->freereason}\" ({$old->freereasondsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'free_reason',
                            'free_reason_id' => $old->freereason,
                            'free_reason_description' => $old->freereasondsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenFreeReasonIds[$old->freereason] = true;

                    $payload[] = [
                        'free_reason_id' => $old->freereason,
                        'free_reason_description' => $old->freereasondsc !== null && $old->freereasondsc !== '' ? $old->freereasondsc : $old->freereason,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $freeReasonRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $freeReasonRows += count($payload);
                }

                $totalRows += $freeReasonRows;
                $freeReasonSummary = "{$oldTableName} → {$newTableName} ({$freeReasonRows} row(s))";
                if ($skippedFreeReasonRows > 0) {
                    $freeReasonSummary .= ", {$skippedFreeReasonRows} duplicate(s) skipped";
                }
                $transferredTables[] = $freeReasonSummary;
            }
            #endregion

            #region Void Reason Conversion
            if ($voidReason) {
                $oldTableName = 'voidreasonfile';
                $newTableName = 'mf_voidreasons';
                $chunkSize = 500;
                $voidReasonRows = 0;
                $skippedVoidReasonRows = 0;
                $payload = [];
                $seenVoidIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenVoidIds[$old->voidcde])) {
                        $skippedVoidReasonRows++;
                        $note = "Skipped duplicate void reason \"{$old->voidcde}\" ({$old->voiddsc}); kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'void_reason',
                            'void_id' => $old->voidcde,
                            'void_description' => $old->voiddsc,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenVoidIds[$old->voidcde] = true;

                    $payload[] = [
                        'void_id' => $old->voidcde,
                        'void_description' => $old->voiddsc !== null && $old->voiddsc !== '' ? $old->voiddsc : $old->voidcde,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $voidReasonRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $voidReasonRows += count($payload);
                }

                $totalRows += $voidReasonRows;
                $voidReasonSummary = "{$oldTableName} → {$newTableName} ({$voidReasonRows} row(s))";
                if ($skippedVoidReasonRows > 0) {
                    $voidReasonSummary .= ", {$skippedVoidReasonRows} duplicate(s) skipped";
                }
                $transferredTables[] = $voidReasonSummary;
            }
            #endregion

            #region Cash In/Out Reason Conversion
            if ($cashInOutReason) {
                $oldTableName = 'cashioreasonfile';
                $newTableName = 'mf_cashioreasons';
                $chunkSize = 500;
                $cashInOutReasonRows = 0;
                $skippedCashInOutReasonRows = 0;
                $payload = [];
                $seenCashIoReasonIds = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table($newTableName)->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    if (isset($seenCashIoReasonIds[$old->cashioreason])) {
                        $skippedCashInOutReasonRows++;
                        $note = "Skipped duplicate cash in/out reason \"{$old->cashioreason}\"; kept first entry by recid order.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'cash_in_out_reason',
                            'cashioreason_id' => $old->cashioreason,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $seenCashIoReasonIds[$old->cashioreason] = true;

                    $payload[] = [
                        'cashioreason_id' => $old->cashioreason,
                        'cashioreason_description' => $old->cashioreason,
                        'cashioreason_type' => $old->type,
                        'is_modified' => $old->ismodified,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table($newTableName)->insert($payload);
                        $cashInOutReasonRows += count($payload);
                        $payload = [];
                    }
                }

                if ($payload !== []) {
                    $targetDb->table($newTableName)->insert($payload);
                    $cashInOutReasonRows += count($payload);
                }

                $totalRows += $cashInOutReasonRows;
                $cashInOutReasonSummary = "{$oldTableName} → {$newTableName} ({$cashInOutReasonRows} row(s))";
                if ($skippedCashInOutReasonRows > 0) {
                    $cashInOutReasonSummary .= ", {$skippedCashInOutReasonRows} duplicate(s) skipped";
                }
                $transferredTables[] = $cashInOutReasonSummary;
            }
            #endregion

            #region Price List Conversion
            if ($priceList) {
                $chunkSize = 500;
                $priceCodeFile1Rows = 0;
                $priceCodeFile2Rows = 0;
                $priceCodeFile3Rows = 0;
                $priceCodeFile4Rows = 0;
                $ensuredBranchIds = [];
                $priceDescriptionsByPriceId = [];

                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                $targetDb->table('mf_price_code_file4')->delete();
                $targetDb->table('mf_price_code_file3')->delete();
                $targetDb->table('mf_price_code_file2')->delete();
                $targetDb->table('mf_price_code_file1')->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                $payload = [];
                foreach ($sourceDb->table('pricecodefile1')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $this->ensureBranchExists($sourceDb, $targetDb, $old->brhcde, $now, $ensuredBranchIds, $conversionNotes);

                    $priceDescriptionsByPriceId[$old->prccde] = $old->prcdsc;

                    $payload[] = [
                        'price_id' => $old->prccde,
                        'price_description' => $old->prcdsc,
                        'pos_price_code' => $old->prcdsc,
                        'currency_id' => $old->curcde !== null && $old->curcde !== '' ? $old->curcde : null,
                        'price_date' => $old->prcdte,
                        'branch_id' => $old->brhcde,
                        'warehouse_id' => $old->warcde !== null && $old->warcde !== '' ? $old->warcde : null,
                        'check_manual' => $old->chkmanual,
                        'is_exported' => $old->isexported,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table('mf_price_code_file1')->insert($payload);
                        $priceCodeFile1Rows += count($payload);
                        $payload = [];
                    }
                }
                if ($payload !== []) {
                    $targetDb->table('mf_price_code_file1')->insert($payload);
                    $priceCodeFile1Rows += count($payload);
                }

                $payload = [];
                foreach ($sourceDb->table('pricecodefile2')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $this->ensureBranchExists($sourceDb, $targetDb, $old->brhcde, $now, $ensuredBranchIds, $conversionNotes);

                    $payload[] = [
                        'price_id' => $old->prccde,
                        'item_id' => $old->itmcde,
                        'item_description' => $old->itmdsc,
                        'unit_of_measure' => $old->untmea,
                        'gross_price' => $old->groprc,
                        'unit_price' => $old->untprc,
                        'unit_cost' => $old->untcst,
                        'currency_id' => $old->curcde,
                        'discount_percent' => $old->disper,
                        'mark_up' => $old->markup,
                        'price_date' => $old->prcdte,
                        'updated_date' => $old->upddte,
                        'customer_item_id' => $old->cusitmcde,
                        'sales_quota' => $old->salquota,
                        'discount_id' => $old->disccde,
                        'branch_id' => $old->brhcde,
                        'pos_price_code' => $priceDescriptionsByPriceId[$old->prccde] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table('mf_price_code_file2')->insert($payload);
                        $priceCodeFile2Rows += count($payload);
                        $payload = [];
                    }
                }
                if ($payload !== []) {
                    $targetDb->table('mf_price_code_file2')->insert($payload);
                    $priceCodeFile2Rows += count($payload);
                }

                $payload = [];
                foreach ($sourceDb->table('pricecodefile3')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $this->ensureBranchExists($sourceDb, $targetDb, $old->brhcde, $now, $ensuredBranchIds, $conversionNotes);

                    $payload[] = [
                        'price_id' => $old->prccde,
                        'branch_id' => $old->brhcde,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table('mf_price_code_file3')->insert($payload);
                        $priceCodeFile3Rows += count($payload);
                        $payload = [];
                    }
                }
                if ($payload !== []) {
                    $targetDb->table('mf_price_code_file3')->insert($payload);
                    $priceCodeFile3Rows += count($payload);
                }

                $payload = [];
                foreach ($sourceDb->table('pricecodefile4')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $payload[] = [
                        'price_id' => $old->prccde,
                        'dine_type_id' => $old->postypcde,
                        'price_date' => $old->prcdte,
                        'pos_price_code' => $priceDescriptionsByPriceId[$old->prccde] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($payload) >= $chunkSize) {
                        $targetDb->table('mf_price_code_file4')->insert($payload);
                        $priceCodeFile4Rows += count($payload);
                        $payload = [];
                    }
                }
                if ($payload !== []) {
                    $targetDb->table('mf_price_code_file4')->insert($payload);
                    $priceCodeFile4Rows += count($payload);
                }

                $totalRows += $priceCodeFile1Rows + $priceCodeFile2Rows + $priceCodeFile3Rows + $priceCodeFile4Rows;
                $transferredTables[] = "pricecodefile1 → mf_price_code_file1 ({$priceCodeFile1Rows} row(s))";
                $transferredTables[] = "pricecodefile2 → mf_price_code_file2 ({$priceCodeFile2Rows} row(s))";
                $transferredTables[] = "pricecodefile3 → mf_price_code_file3 ({$priceCodeFile3Rows} row(s))";
                $transferredTables[] = "pricecodefile4 → mf_price_code_file4 ({$priceCodeFile4Rows} row(s))";
            }
            #endregion

            if ($totalRows === 0) {
                throw new RuntimeException('No rows were transferred.');
            }

            return response()->json([
                'ok' => true,
                'rows' => $totalRows,
                'tables' => $transferredTables,
                'notes' => $conversionNotes,
                'message' => "Transferred {$totalRows} row(s) from {$source->database} to {$target->database}.",
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * @return list<string>
     */
    protected function conversionKeys(Request $request): array
    {
        $keys = [];

        foreach ($this->conversionOptions as $input => $key) {
            if ($request->boolean($input)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @return array{0: RemoteDatabaseConfig, 1: RemoteDatabaseConfig}
     */
    protected function resolveDatabases(Request $request): array
    {
        $validated = $request->validate([
            ...$this->connectionRules(),
            'source_database' => ['required', 'string', 'max:255'],
            'target_database' => ['required', 'string', 'max:255', 'different:source_database'],
            // ...$this->conversionRules(),
        ]);

        $connection = $this->extractConnection($validated);

        return [
            RemoteDatabaseConfig::fromConnectionAndDatabase($connection, $validated['source_database']),
            RemoteDatabaseConfig::fromConnectionAndDatabase($connection, $validated['target_database']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateConnection(Request $request): array
    {
        return $request->validate($this->connectionRules());
    }

    /**
     * @return array<string, list<string>>
     */
    protected function conversionRules(): array
    {
        return array_fill_keys(
            array_keys($this->conversionOptions),
            ['sometimes', 'boolean'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectionRules(): array
    {
        return [
            'driver' => ['required', 'in:mysql,mariadb,pgsql,sqlsrv'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'charset' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function extractConnection(array $validated): array
    {
        return [
            'driver' => $validated['driver'],
            'host' => $validated['host'],
            'port' => $validated['port'],
            'username' => $validated['username'],
            'password' => $validated['password'] ?? '',
            'charset' => $validated['charset'] ?? null,
        ];
    }

    /**
     * @param  array<string, true>  $ensuredBranchIds
     * @param  list<string>  $conversionNotes
     */
    protected function ensureBranchExists(
        mixed $sourceDb,
        mixed $targetDb,
        mixed $branchId,
        mixed $now,
        array &$ensuredBranchIds,
        array &$conversionNotes,
    ): void {
        $branchId = trim((string) $branchId);
        if ($branchId === '') {
            return;
        }

        if (isset($ensuredBranchIds[$branchId])) {
            return;
        }
        $ensuredBranchIds[$branchId] = true;

        if ($targetDb->table('mf_branch')->where('branch_id', $branchId)->exists()) {
            return;
        }

        $old = $sourceDb->table('branchfile')->where('brhcde', $branchId)->first();

        // if ($old !== null) {
        //     $targetDb->table('mf_branch')->insert([
        //         'branch_id' => $old->brhcde,
        //         'branch_description' => $old->brhdsc,
        //         'branch_prefix' => $this->generateBranchPrefix(
        //             $this->optionalRowValue($old, 'prefix'),
        //             $old->brhdsc,
        //             $old->brhcde,
        //         ),
        //         'business1' => $this->optionalRowValue($old, 'business1'),
        //         'business2' => $this->optionalRowValue($old, 'business2'),
        //         'business3' => $this->optionalRowValue($old, 'business3'),
        //         'address1' => $this->optionalRowValue($old, 'address1'),
        //         'address2' => $this->optionalRowValue($old, 'address2'),
        //         'address3' => $this->optionalRowValue($old, 'address3'),
        //         'created_at' => $now,
        //         'updated_at' => $now,
        //     ]);

        //     if (! $targetDb->table('date_locking')->where('branch_id', $branchId)->exists()) {
        //         $targetDb->table('date_locking')->insert([
        //             'branch_id' => $old->brhcde,
        //             'sales_date_lock1' => $this->optionalRowValue($old, 'saldatelock1'),
        //             'sales_date_lock2' => $this->optionalRowValue($old, 'saldatelock2'),
        //             'created_at' => $now,
        //             'updated_at' => $now,
        //         ]);
        //     }

        //     $note = "Created missing branch \"{$branchId}\" from branchfile for price list conversion.";
        // } else {
            $targetDb->table('mf_branch')->insert([
                'branch_id' => $branchId,
                'branch_description' => $branchId,
                'branch_prefix' => $this->generateBranchPrefix(null, $branchId, $branchId),
                'business1' => null,
                'business2' => null,
                'business3' => null,
                'address1' => null,
                'address2' => null,
                'address3' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $note = "Created stub branch \"{$branchId}\" (not found in branchfile) for price list conversion.";
        // }

        $conversionNotes[] = $note;
        Log::warning($note);
    }

    protected function optionalRowValue(mixed $row, string $property, mixed $default = null): mixed
    {
        if (! is_object($row) || ! property_exists($row, $property)) {
            return $default;
        }

        $value = $row->{$property};

        return $value !== null && $value !== '' ? $value : $default;
    }

    protected function generateBranchPrefix(
        mixed $sourcePrefix,
        mixed $branchDescription,
        mixed $branchId = null,
    ): string {
        $normalizedPrefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $sourcePrefix) ?? '');
        if ($normalizedPrefix !== '') {
            return $this->readableTokenShortcut($normalizedPrefix);
        }

        $description = trim((string) $branchDescription);
        $words = array_values(array_filter(
            preg_split('/\s+/', preg_replace('/[^a-zA-Z0-9\s]/', ' ', $description)) ?: []
        ));

        if ($words === []) {
            $fallback = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $branchId) ?? '');

            return $this->readableTokenShortcut($fallback);
        }

        if (count($words) === 1) {
            return $this->readableTokenShortcut(strtoupper($words[0]));
        }

        $firstWord = strtoupper($words[0]);
        if (strlen($firstWord) >= 4) {
            return substr($firstWord, 0, 2).substr($firstWord, -2).strtoupper($words[1][0]);
        }

        $prefix = '';
        foreach ($words as $word) {
            if ($prefix !== '' && strlen($prefix) >= 5) {
                break;
            }
            $prefix .= strtoupper($word[0]);
        }

        if (strlen($prefix) < 5) {
            $prefix .= strtoupper(substr($words[0], 1, 5 - strlen($prefix)));
        }

        return $this->readableTokenShortcut($prefix);
    }

    protected function readableTokenShortcut(string $token, int $length = 5): string
    {
        $token = strtoupper(preg_replace('/[^A-Z0-9]/', '', $token) ?? '');

        if ($token === '') {
            return str_pad('', $length, 'X');
        }

        if (strlen($token) <= $length) {
            return str_pad($token, $length, 'X');
        }

        $head = (int) ceil($length / 2);
        $tail = $length - $head;

        return substr($token, 0, $head).substr($token, -$tail);
    }

    protected function resolveUnitOfMeasureId(
        mixed $unitOfMeasure,
        mixed $defaultUnitOfMeasureId,
        array $unitOfMeasureIdsByCode,
    ): mixed {
        $code = strtoupper(trim((string) $unitOfMeasure));
        if ($code === '') {
            return null;
        }

        if ($code === 'PCS') {
            return $defaultUnitOfMeasureId;
        }

        return $unitOfMeasureIdsByCode[$code] ?? null;
    }

    /**
     * @param  callable(): array<string, mixed>  $action
     */
    protected function respondJson(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
