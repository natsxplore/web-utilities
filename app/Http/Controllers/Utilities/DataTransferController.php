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
                        'business1' => $old->business1,
                        'business2' => $old->business2,
                        'business3' => $old->business3,
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
                    'console_file_sync' => $old->consofilesync ?? 1,
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
                         ->where('branch_description', '!=', 'MAIN')
                         ->delete();
                $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');

                foreach ($sourceDb->table($oldTableName)->orderBy('recid')->lazy($chunkSize) as $old) {
                    $branchId = $old->brhcde;   // Use brhcde as branch_id

                    $payload[] = [
                        'branch_id' => $branchId,
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
                        'branch_id' => $branchId,
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

                $tablesToUpdate = [
                        'document_file_number_series',
                        'mf_inventory_transactiontype_file2',
                        'mf_warehouses',
                        // Add more tables here if needed
                    ];

                foreach ($tablesToUpdate as $table) {
                    $targetDb->table($table)
                            ->whereIn('branch_id', ['LSTVDEFAULTBRANCH1', 'ALL'])
                            ->update(['branch_id' => 'ALL']);
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
                $chunkSize = 500;
                $companyId = trim((string) $request->input('company_name', ''));
                $ensuredBranchIds = [];
                $posUserRows = 0;
                $userReportTypeRows = 0;
                $skippedUserReportTypeRows = 0;
                $userBranchRows = 0;
                $skippedUserBranchRows = 0;
                $posUserMenuRows = 0;
                $skippedPosUserMenuRows = 0;
                $userMenuRows = 0;
                $userMenuActionRows = 0;
                $skippedUserMenuRows = 0;
                $validPosUserIds = [];
                $posUserSourceMissing = false;
                $userReportTypeSourceMissing = false;
                $userBranchSourceMissing = false;
                $posUserMenuSourceMissing = false;
                $userMenuSourceMissing = false;
                $appUserRows = 0;
                $appUserSourceMissing = false;

                // 1. pos_userfile → mf_pos_users + users (app login)
                if ($this->sourceTableExists($sourceDb, 'pos_userfile')) {

                if ($companyId === '') {
                    throw new RuntimeException('Company ID is required for users conversion (used as first_name). Enter it in the Company ID field.');
                }

                $posUserPayload = [];

                $appUserPayload = [];
                $posUserUpdateColumns = [
                    'username',
                    'password',
                    'user_type',
                    'email',
                    'approver',
                    'receive_z_reading',
                    'print_range',
                    'card_holder',
                    'card_number',
                    'updated_at',
                ];

                $appUserUpdateColumns = [
                    'username',
                    'last_name',
                    'password',
                    'user_type',
                    'first_name',
                    'updated_at',
                ];

                foreach ($sourceDb->table('pos_userfile')->orderBy('recid')->lazy($chunkSize) as $old) {

                    $userId = trim((string) ($old->usrcde ?? ''));
                    if ($userId === '') {
                        continue;
                    }

                    $validPosUserIds[$userId] = true;
                    $userType = trim((string) ($this->optionalRowValue($old, 'usrtyp') ?? ''));
                    $userType = $userType === '' ? 'User' : $userType;
                    $password = $this->optionalRowValue($old, 'usrpwd');

                    $posUserPayload[] = [
                        'user_id' => $userId,
                        'username' => $userId,
                        'password' => $password,
                        'user_type' => $userType,
                        'email' => $this->optionalRowValue($old, 'email'),
                        'approver' => $this->optionalRowValue($old, 'approver'),
                        'receive_z_reading' => $this->optionalRowValue($old, 'receive_zreading'),
                        'print_range' => $this->optionalRowValue($old, 'prntrange'),
                        'card_holder' => $this->optionalRowValue($old, 'cardholder'),
                        'card_number' => $this->optionalRowValue($old, 'cardno'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $appUserPayload[] = [
                        'user_id' => $userId,
                        'username' => $userId,
                        'last_name' => $userId,
                        'password' => $password,
                        'user_type' => $userType,
                        'last_used_branch_id' => null,
                        'first_name' => $companyId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($posUserPayload) >= $chunkSize) {
                        $posUserRows += $this->upsertChunked($targetDb, 'mf_pos_users', $posUserPayload, ['user_id'], $posUserUpdateColumns);
                        $appUserRows += $this->upsertChunked($targetDb, 'users', $appUserPayload, ['user_id'], $appUserUpdateColumns);
                        $posUserPayload = [];
                        $appUserPayload = [];
                    }
                }

                if ($posUserPayload !== []) {
                    $posUserRows += $this->upsertChunked($targetDb, 'mf_pos_users', $posUserPayload, ['user_id'], $posUserUpdateColumns);
                }
                if ($appUserPayload !== []) {
                    $appUserRows += $this->upsertChunked($targetDb, 'users', $appUserPayload, ['user_id'], $appUserUpdateColumns);
                }
                } else {
                    $posUserSourceMissing = true;
                    $appUserSourceMissing = true;
                    $this->noteMissingSourceTable('pos_userfile', $source, $target, $conversionNotes);
                }

                if ($validPosUserIds === []) {
                    foreach ($targetDb->table('mf_pos_users')->pluck('user_id') as $posUserId) {
                        $validPosUserIds[(string) $posUserId] = true;
                    }
                }

                /*
                 * Legacy users → users from source `users` table (lastbrnch, usrlvl, emailadd).
                 * Disabled: app users are built from pos_userfile in step 1 above.
                 *
                 * if ($this->sourceTableExists($sourceDb, 'users')) { ... }
                 */

                // 2. userreporttypefile → mf_user_report_types
                if ($this->sourceTableExists($sourceDb, 'userreporttypefile')) {
                $userReportTypePayload = [];
                $userReportTypeUpdateColumns = ['updated_at'];

                foreach ($sourceDb->table('userreporttypefile')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $userId = trim((string) ($old->usrcde ?? ''));
                    $reportType = trim((string) ($old->reptype ?? ''));

                    if ($userId === '' || $reportType === '' || ! isset($validPosUserIds[$userId])) {
                        $skippedUserReportTypeRows++;
                        $note = "Skipped user report type for user \"{$userId}\" ({$reportType}): user not found in mf_pos_users.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'user_file',
                            'user_id' => $userId,
                            'report_type' => $reportType,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $userReportTypePayload[] = [
                        'user_id' => $userId,
                        'report_type' => $reportType,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($userReportTypePayload) >= $chunkSize) {
                        $userReportTypeRows += $this->upsertChunked($targetDb,'mf_user_report_types',$userReportTypePayload,['user_id', 'report_type'],$userReportTypeUpdateColumns);
                        $userReportTypePayload = [];
                    }
                }

                if ($userReportTypePayload !== []) {
                    $userReportTypeRows += $this->upsertChunked($targetDb,'mf_user_report_types',$userReportTypePayload,['user_id', 'report_type'],$userReportTypeUpdateColumns);
                }
                } else {
                    $userReportTypeSourceMissing = true;
                    $this->noteMissingSourceTable('userreporttypefile', $source, $target, $conversionNotes);
                }

                // 4. userbranchfile → mf_user_branch_file
                if ($this->sourceTableExists($sourceDb, 'userbranchfile')) {
                $userBranchPayload = [];
                $userBranchUpdateColumns = ['updated_at'];

                foreach ($sourceDb->table('userbranchfile')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $userId = trim((string) ($old->usrcde ?? ''));
                    $branchId = trim((string) ($old->brhcde ?? ''));

                    if ($userId === '' || ! isset($validPosUserIds[$userId])) {
                        $skippedUserBranchRows++;
                        $note = "Skipped user branch for user \"{$userId}\" ({$branchId}): user not found in mf_pos_users.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'user_file',
                            'user_id' => $userId,
                            'branch_id' => $branchId,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    if ($branchId === '') {
                        $skippedUserBranchRows++;
                        $note = "Skipped user branch for user \"{$userId}\": missing branch_id.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'user_file',
                            'user_id' => $userId,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $this->ensureBranchExists($sourceDb, $targetDb, $branchId, $now, $ensuredBranchIds, $conversionNotes);

                    $userBranchPayload[] = [
                        'user_id' => $userId,
                        'branch_id' => $branchId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($userBranchPayload) >= $chunkSize) {
                        $userBranchRows += $this->upsertChunked($targetDb,'mf_user_branch_file',$userBranchPayload,['user_id', 'branch_id'],$userBranchUpdateColumns);
                        $userBranchPayload = [];
                    }
                }

                if ($userBranchPayload !== []) {
                    $userBranchRows += $this->upsertChunked($targetDb,'mf_user_branch_file',$userBranchPayload,['user_id', 'branch_id'],$userBranchUpdateColumns);
                }
                } else {
                    $userBranchSourceMissing = true;
                    $this->noteMissingSourceTable('userbranchfile', $source, $target, $conversionNotes);
                }

                // 5. pos_user_menus → pos_user_menus
                if ($this->sourceTableExists($sourceDb, 'pos_user_menus')) {
                $menuCaptionAliases = ['tenant' => 'warehouse'];
                $posMenuIdsByCaption = [];
                foreach ($targetDb->table('pos_menus')->get(['record_id', 'menu_caption']) as $posMenuRow) {
                    $lookupKey = $this->normalizeLookupKey((string) ($posMenuRow->menu_caption ?? ''), $menuCaptionAliases);
                    if ($lookupKey !== '') {
                        $posMenuIdsByCaption[$lookupKey] = $posMenuRow->record_id;
                    }
                }

                $targetDb->table('pos_user_menus')->delete();

                $posUserMenuPayload = [];
                foreach ($sourceDb->table('pos_user_menus')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $userId = trim((string) ($old->usrcde ?? ''));
                    $branchId = trim((string) ($old->brhcde ?? ''));
                    $menuCaption = trim((string) ($old->mencap ?? ''));
                    $lookupKey = $this->normalizeLookupKey($menuCaption, $menuCaptionAliases);
                    $menuId = $lookupKey !== '' ? ($posMenuIdsByCaption[$lookupKey] ?? null) : null;

                    if ($userId === '' || ! isset($validPosUserIds[$userId])) {
                        $skippedPosUserMenuRows++;
                        $note = "Skipped POS user menu for user \"{$userId}\" ({$menuCaption}): user not found in mf_pos_users.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'user_file',
                            'user_id' => $userId,
                            'menu_caption' => $menuCaption,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    if ($branchId === '') {
                        $skippedPosUserMenuRows++;
                        $note = "Skipped POS user menu for user \"{$userId}\" ({$menuCaption}): missing branch_id.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'user_file',
                            'user_id' => $userId,
                            'menu_caption' => $menuCaption,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    if ($menuId === null) {
                        $skippedPosUserMenuRows++;
                        $note = "Skipped POS user menu for user \"{$userId}\": menu caption \"{$menuCaption}\" not found in pos_menus.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'user_file',
                            'user_id' => $userId,
                            'menu_caption' => $menuCaption,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $this->ensureBranchExists($sourceDb, $targetDb, $branchId, $now, $ensuredBranchIds, $conversionNotes);

                    $posUserMenuPayload[] = [
                        'user_id' => $userId,
                        'branch_id' => $branchId,
                        'menu_id' => $menuId,
                        'menu_caption' => $menuCaption,
                        'menu_group' => $this->optionalRowValue($old, 'mengrp'),
                        'has_add' => $this->optionalRowValue($old, 'has_add'),
                        'has_delete' => $this->optionalRowValue($old, 'has_delete'),
                        'has_edit' => $this->optionalRowValue($old, 'has_edit'),
                        'has_import' => $this->optionalRowValue($old, 'has_import'),
                        'has_print' => $this->optionalRowValue($old, 'has_print'),
                        'has_resend' => $this->optionalRowValue($old, 'has_resend'),
                        'has_void' => $this->optionalRowValue($old, 'has_void'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($posUserMenuPayload) >= $chunkSize) {
                        $targetDb->table('pos_user_menus')->insert($posUserMenuPayload);
                        $posUserMenuRows += count($posUserMenuPayload);
                        $posUserMenuPayload = [];
                    }
                }

                if ($posUserMenuPayload !== []) {
                    $targetDb->table('pos_user_menus')->insert($posUserMenuPayload);
                    $posUserMenuRows += count($posUserMenuPayload);
                }
                } else {
                    $posUserMenuSourceMissing = true;
                    $this->noteMissingSourceTable('pos_user_menus', $source, $target, $conversionNotes);
                }

                // 6. user_menus → user_menus + user_menu_actions (not yet enabled)
                // if ($this->sourceTableExists($sourceDb, 'user_menus')) {
                //     $menuIdsByProgram = [];
                //     $menuIdsByCaption = [];
                //     foreach ($targetDb->table('menus')->get(['menu_id', 'menprg', 'mencap']) as $menuRow) {
                //         $programKey = $this->normalizeLookupKey((string) ($menuRow->menprg ?? ''));
                //         $captionKey = $this->normalizeLookupKey((string) ($menuRow->mencap ?? ''));
                //         if ($programKey !== '') {
                //             $menuIdsByProgram[$programKey] = $menuRow->menu_id;
                //         }
                //         if ($captionKey !== '') {
                //             $menuIdsByCaption[$captionKey] = $menuRow->menu_id;
                //         }
                //     }

                //     $userMenuPayload = [];
                //     $userMenuUpdateColumns = ['active', 'updated_at'];
                //     $userMenuActionPayload = [];
                //     $userMenuActionUpdateColumns = [
                //         'allow_add',
                //         'allow_edit',
                //         'allow_delete',
                //         'allow_print',
                //         'allow_cancel',
                //         'updated_at',
                //     ];

                //     foreach ($sourceDb->table('user_menus')->orderBy('recid')->lazy($chunkSize) as $old) {
                //         $userId = trim((string) ($old->usrcde ?? ''));
                //         $menuProgram = trim((string) ($old->menprg ?? ''));
                //         $menuCaption = trim((string) ($old->mencap ?? ''));
                //         $programKey = $this->normalizeLookupKey($menuProgram);
                //         $captionKey = $this->normalizeLookupKey($menuCaption);
                //         $menuId = $programKey !== ''
                //             ? ($menuIdsByProgram[$programKey] ?? null)
                //             : null;

                //         if ($menuId === null && $captionKey !== '') {
                //             $menuId = $menuIdsByCaption[$captionKey] ?? null;
                //         }

                //         if ($userId === '' || ! isset($validPosUserIds[$userId])) {
                //             $skippedUserMenuRows++;
                //             $note = "Skipped user menu for user \"{$userId}\" ({$menuProgram}/{$menuCaption}): user not found in mf_pos_users.";
                //             $conversionNotes[] = $note;
                //             Log::warning($note, [
                //                 'conversion' => 'user_file',
                //                 'user_id' => $userId,
                //                 'menu_program' => $menuProgram,
                //                 'menu_caption' => $menuCaption,
                //                 'source_database' => $source->database,
                //                 'target_database' => $target->database,
                //             ]);

                //             continue;
                //         }

                //         if ($menuId === null) {
                //             $skippedUserMenuRows++;
                //             $note = "Skipped user menu for user \"{$userId}\": menu \"{$menuProgram}\" / \"{$menuCaption}\" not found in menus.";
                //             $conversionNotes[] = $note;
                //             Log::warning($note, [
                //                 'conversion' => 'user_file',
                //                 'user_id' => $userId,
                //                 'menu_program' => $menuProgram,
                //                 'menu_caption' => $menuCaption,
                //                 'source_database' => $source->database,
                //                 'target_database' => $target->database,
                //             ]);

                //             continue;
                //         }

                //         $userMenuPayload[] = [
                //             'user_id' => $userId,
                //             'menu_id' => $menuId,
                //             'active' => 1,
                //             'created_at' => $now,
                //             'updated_at' => $now,
                //         ];

                //         $userMenuActionPayload[] = [
                //             'user_id' => $userId,
                //             'menu_id' => $menuId,
                //             'allow_add' => $this->optionalRowValue($old, 'has_add'),
                //             'allow_edit' => $this->optionalRowValue($old, 'has_edit'),
                //             'allow_delete' => $this->optionalRowValue($old, 'has_delete'),
                //             'allow_print' => $this->optionalRowValue($old, 'has_print'),
                //             'allow_cancel' => $this->optionalRowValue($old, 'has_void'),
                //             'created_at' => $now,
                //             'updated_at' => $now,
                //         ];

                //         if (count($userMenuPayload) >= $chunkSize) {
                //             $userMenuRows += $this->upsertChunked(
                //                 $targetDb,
                //                 'user_menus',
                //                 $userMenuPayload,
                //                 ['user_id', 'menu_id'],
                //                 $userMenuUpdateColumns,
                //             );
                //             $userMenuActionRows += $this->upsertChunked(
                //                 $targetDb,
                //                 'user_menu_actions',
                //                 $userMenuActionPayload,
                //                 ['user_id', 'menu_id'],
                //                 $userMenuActionUpdateColumns,
                //             );
                //             $userMenuPayload = [];
                //             $userMenuActionPayload = [];
                //         }
                //     }

                //     if ($userMenuPayload !== []) {
                //         $userMenuRows += $this->upsertChunked(
                //             $targetDb,
                //             'user_menus',
                //             $userMenuPayload,
                //             ['user_id', 'menu_id'],
                //             $userMenuUpdateColumns,
                //         );
                //         $userMenuActionRows += $this->upsertChunked(
                //             $targetDb,
                //             'user_menu_actions',
                //             $userMenuActionPayload,
                //             ['user_id', 'menu_id'],
                //             $userMenuActionUpdateColumns,
                //         );
                //     }
                // } else {
                //     $userMenuSourceMissing = true;
                //     $this->noteMissingSourceTable('user_menus', $source, $target, $conversionNotes);
                // }

                $totalRows += $posUserRows + $appUserRows + $userReportTypeRows + $userBranchRows + $posUserMenuRows + $userMenuRows + $userMenuActionRows;

                $transferredTables[] = $posUserSourceMissing
                    ? 'pos_userfile → mf_pos_users (skipped, source table not found)'
                    : "pos_userfile → mf_pos_users ({$posUserRows} row(s))";

                $appUserSummary = $appUserSourceMissing
                    ? 'pos_userfile → users (skipped, source table not found)'
                    : "pos_userfile → users ({$appUserRows} row(s))";
                $transferredTables[] = $appUserSummary;

                $userReportTypeSummary = $userReportTypeSourceMissing
                    ? 'userreporttypefile → mf_user_report_types (skipped, source table not found)'
                    : "userreporttypefile → mf_user_report_types ({$userReportTypeRows} row(s))";
                if ($skippedUserReportTypeRows > 0) {
                    $userReportTypeSummary .= ", {$skippedUserReportTypeRows} skipped (missing user)";
                }
                $transferredTables[] = $userReportTypeSummary;

                $userBranchSummary = $userBranchSourceMissing
                    ? 'userbranchfile → mf_user_branch_file (skipped, source table not found)'
                    : "userbranchfile → mf_user_branch_file ({$userBranchRows} row(s))";
                if ($skippedUserBranchRows > 0) {
                    $userBranchSummary .= ", {$skippedUserBranchRows} skipped (missing user or branch)";
                }
                $transferredTables[] = $userBranchSummary;

                $posUserMenuSummary = $posUserMenuSourceMissing
                    ? 'pos_user_menus → pos_user_menus (skipped, source table not found)'
                    : "pos_user_menus → pos_user_menus ({$posUserMenuRows} row(s))";
                if ($skippedPosUserMenuRows > 0) {
                    $posUserMenuSummary .= ", {$skippedPosUserMenuRows} skipped (missing user, branch, or menu)";
                }
                $transferredTables[] = $posUserMenuSummary;

                $userMenuSummary = $userMenuSourceMissing
                    ? 'user_menus → user_menus (skipped, source table not found)'
                    : "user_menus → user_menus ({$userMenuRows} row(s)), user_menu_actions ({$userMenuActionRows} row(s))";
                if ($skippedUserMenuRows > 0) {
                    $userMenuSummary .= ", {$skippedUserMenuRows} skipped (missing user or menu)";
                }
                $transferredTables[] = $userMenuSummary;
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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

                $classificationDescriptionsById = $sourceDb->table('itemclassfile')
                    ->pluck('itmcladsc', 'itmclacde')
                    ->all();

                foreach ($targetDb->table('mf_itemclassifications')->get(['item_classification_id', 'item_classification_description']) as $classificationRow) {
                    $classificationDescriptionsById[$classificationRow->item_classification_id] = $classificationRow->item_classification_description;
                }

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

                    $classificationId = trim((string) ($old->itmclacde ?? ''));

                    $payload[] = [
                        'item_subclassification_id' => $old->itemsubclasscde,
                        'item_subclassification_description' => $old->itemsubclassdsc,
                        'prev_item_subclassification_id' => $old->prev_itemsubclasscde,
                        'item_classification_id' => $classificationId !== '' ? $classificationId : null,
                        'item_classification_description' => $classificationId !== ''
                            ? ($classificationDescriptionsById[$classificationId] ?? null)
                            : null,
                        'location_id' => $old->locationcde,
                        'last_modified' => $old->lastmod,
                        'hide_subclass' => $old->hide_subclass,
                        'subclass_image' => $old->subclassimage,
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_exported' => $old->isexported ?? 1,
                        'is_modified' => $old->ismodified ?? 1,
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

                $validAccountIds = [];
                if ($this->sourceTableExists($targetDb, 'accounts')) {
                    foreach ($targetDb->table('accounts')->pluck('account_id') as $accountId) {
                        $validAccountIds[(string) $accountId] = true;
                    }
                }

                $validTaxIds = $targetDb->table('mf_vat_codes')->pluck('tax_id')->flip()->all();
                $validCurrencyIds = $targetDb->table('mf_currencies')->pluck('currency_id')->flip()->all();
                $validMemcIds = $targetDb->table('mf_memcfile')->pluck('memc_id')->flip()->all();

                $validGlDepartmentIds = [];
                $glDepartmentTable = $this->sourceTableExists($targetDb, 'mf_gldepartments')
                    ? 'mf_gldepartments'
                    : ($this->sourceTableExists($targetDb, 'mf_gl_departments') ? 'mf_gl_departments' : null);
                if ($glDepartmentTable !== null) {
                    foreach ($targetDb->table($glDepartmentTable)->pluck('gl_department_id') as $glDepartmentId) {
                        $validGlDepartmentIds[(string) $glDepartmentId] = true;
                    }
                }

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
                        'item_description' => trim((string) ($old->itmdsc ?? '')),
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
                        'cgs_account_id' => $this->resolveForeignKeyId($old->cgsactcde, $validAccountIds, $nullifiedItemReferences),
                        'sales_discount_account_id' => $this->resolveForeignKeyId($old->saldisact, $validAccountIds, $nullifiedItemReferences),
                        'purchase_discount_account_id' => $this->resolveForeignKeyId($old->purdisact, $validAccountIds, $nullifiedItemReferences),
                        'sales_account_id' => $this->resolveForeignKeyId($old->salactcde, $validAccountIds, $nullifiedItemReferences),
                        'inventory_account_id' => $this->resolveForeignKeyId($old->invactcde, $validAccountIds, $nullifiedItemReferences),
                        'sales_return_account_id' => $this->resolveForeignKeyId($old->srtactcde, $validAccountIds, $nullifiedItemReferences),
                        'tax_id' => $this->resolveForeignKeyId($old->taxcde, $validTaxIds, $nullifiedItemReferences),
                        'purchase_return_account_id' => $this->resolveForeignKeyId($old->prtactcde, $validAccountIds, $nullifiedItemReferences),
                        'purchase_account_id' => $this->resolveForeignKeyId($old->puractcde, $validAccountIds, $nullifiedItemReferences),
                        'purchase_tax_id' => $this->resolveForeignKeyId($old->purtaxcde, $validTaxIds, $nullifiedItemReferences),
                        'sales_ewt_id' => $this->resolveForeignKeyId($old->salewtcde, $validTaxIds, $nullifiedItemReferences),
                        'purchase_ewt_id' => $this->resolveForeignKeyId($old->purewtcde, $validTaxIds, $nullifiedItemReferences),
                        'sales_evat_id' => $this->resolveForeignKeyId($old->salevatcde, $validTaxIds, $nullifiedItemReferences),
                        'purchase_evat_id' => $this->resolveForeignKeyId($old->purevatcde, $validTaxIds, $nullifiedItemReferences),
                        'sales_currency_id' => $this->resolveForeignKeyId($old->salcur, $validCurrencyIds, $nullifiedItemReferences),
                        'purchase_currency_id' => $this->resolveForeignKeyId($old->purcur, $validCurrencyIds, $nullifiedItemReferences),
                        'item_picture1' => $old->itmpic,
                        'item_picture2' => $old->itmpic2,
                        'item_picture3' => $old->itmpic3,
                        'is_combo_meal' => $old->chkcombo,
                        'memc_id' => $this->resolveForeignKeyId($old->memc, $validMemcIds, $nullifiedItemReferences),
                        'foreign_description' => $old->itmdscforeign,
                        'non_trade' => $old->chknontrd,
                        'gl_department_id' => $this->resolveForeignKeyId($old->gldepcde, $validGlDepartmentIds, $nullifiedItemReferences),
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                            'sales_currency_id' => $this->resolveForeignKeyId($old->salcur, $validCurrencyIds),
                            'purchase_currency_id' => $this->resolveForeignKeyId($old->purcur, $validCurrencyIds),
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
                            'sales_currency_id' => $this->resolveForeignKeyId($old->salcur, $validCurrencyIds),
                            'purchase_currency_id' => $this->resolveForeignKeyId($old->purcur, $validCurrencyIds),
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                        'is_modified' => $old->ismodified ?? 1,
                        'is_exported' => $old->isexported ?? 1,
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
                $skippedPriceCodeFile2Rows = 0;
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
                        'is_exported' => $old->isexported ?? 1,
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

                $validPriceIds = array_fill_keys(array_keys($priceDescriptionsByPriceId), true);
                $itemDescriptionsById = [];
                foreach ($targetDb->table('mf_items')->get(['item_id', 'item_description']) as $itemRow) {
                    $itemDescriptionsById[$itemRow->item_id] = trim((string) ($itemRow->item_description ?? ''));
                }

                $payload = [];
                foreach ($sourceDb->table('pricecodefile2')->orderBy('recid')->lazy($chunkSize) as $old) {
                    $priceId = trim((string) ($old->prccde ?? ''));
                    $itemId = trim((string) ($old->itmcde ?? ''));
                    $itemDescription = trim((string) ($old->itmdsc ?? ''));

                    if ($priceId === '' || ! isset($validPriceIds[$priceId])) {
                        $skippedPriceCodeFile2Rows++;
                        $note = "Skipped price list detail for item \"{$itemId}\" ({$itemDescription}): price_id \"{$priceId}\" not found in mf_price_code_file1.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'price_list',
                            'price_id' => $priceId,
                            'item_id' => $itemId,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    if ($itemId === '' || ! array_key_exists($itemId, $itemDescriptionsById)) {
                        $skippedPriceCodeFile2Rows++;
                        $note = "Skipped price list detail for price \"{$priceId}\": item_id \"{$itemId}\" not found in mf_items.";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'price_list',
                            'price_id' => $priceId,
                            'item_id' => $itemId,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $masterItemDescription = $itemDescriptionsById[$itemId];

                    if ($itemDescription !== $masterItemDescription) {
                        $skippedPriceCodeFile2Rows++;
                        $note = "Skipped price list detail for price \"{$priceId}\": item_id \"{$itemId}\" description \"{$itemDescription}\" does not match item master \"{$masterItemDescription}\".";
                        $conversionNotes[] = $note;
                        Log::warning($note, [
                            'conversion' => 'price_list',
                            'price_id' => $priceId,
                            'item_id' => $itemId,
                            'item_description' => $itemDescription,
                            'master_item_description' => $masterItemDescription,
                            'source_database' => $source->database,
                            'target_database' => $target->database,
                        ]);

                        continue;
                    }

                    $this->ensureBranchExists($sourceDb, $targetDb, $old->brhcde, $now, $ensuredBranchIds, $conversionNotes);

                    $unitPrice = $old->untprc;
                    $grossPrice = $old->groprc;

                    if (is_numeric($unitPrice) && (float) $unitPrice != 0.0 && (! is_numeric($grossPrice) || (float) $grossPrice == 0.0)) {
                        $grossPrice = $unitPrice;
                    }

                    $payload[] = [
                        'price_id' => $old->prccde,
                        'item_id' => $old->itmcde,
                        'item_description' => $masterItemDescription,
                        'unit_of_measure' => $old->untmea,
                        'gross_price' => $grossPrice,
                        'unit_price' => $unitPrice,
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
                $priceCodeFile2Summary = "pricecodefile2 → mf_price_code_file2 ({$priceCodeFile2Rows} row(s))";
                if ($skippedPriceCodeFile2Rows > 0) {
                    $priceCodeFile2Summary .= ", {$skippedPriceCodeFile2Rows} row(s) skipped (missing item, parent price, or description mismatch)";
                }
                $transferredTables[] = $priceCodeFile2Summary;
                $transferredTables[] = "pricecodefile3 → mf_price_code_file3 ({$priceCodeFile3Rows} row(s))";
                $transferredTables[] = "pricecodefile4 → mf_price_code_file4 ({$priceCodeFile4Rows} row(s))";
            }
            #endregion

            #region Inventory Transaction Conversion
            if ($inventoryTransaction) {
                $chunkSize = 500;
                $ensuredBranchIds = [];
                $transactionTypeRows = 0;
                $inventoryFile1Rows = 0;
                $inventoryFile2Rows = 0;
                $skippedInventoryFile1Rows = 0;
                $skippedInventoryFile2Rows = 0;
                $nullifiedInventoryBranches = 0;
                $transactionTypeSourceMissing = false;
                $inventoryFile1SourceMissing = false;
                $inventoryFile2SourceMissing = false;
                $validTransactionTypeIds = [];

                $hasInventorySource = $this->sourceTableExists($sourceDb, 'trantypefile')
                    || $this->sourceTableExists($sourceDb, 'inventorytranfile1')
                    || $this->sourceTableExists($sourceDb, 'inventorytranfile2');

                if ($hasInventorySource) {
                    $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                    $targetDb->table('trn_inventory_transaction_file2')->delete();
                    $targetDb->table('trn_inventory_transaction_file1')->delete();
                    $targetDb->table('mf_inventory_transactiontypes')->delete();
                    $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');
                }

                // 1. trantypefile → mf_inventory_transactiontypes
                if ($this->sourceTableExists($sourceDb, 'trantypefile')) {
                    $payload = [];

                    foreach ($sourceDb->table('trantypefile')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $transactionTypeId = trim((string) ($this->optionalRowValue($old, 'trntypcde') ?? ''));
                        if ($transactionTypeId === '') {
                            continue;
                        }

                        $validTransactionTypeIds[$transactionTypeId] = true;

                        $payload[] = [
                            'transaction_type_id' => $transactionTypeId,
                            'transaction_type_code' => $transactionTypeId,
                            'document_number' => $this->optionalRowValue($old, 'docnum'),
                            'transaction_type_description' => $this->optionalRowValue($old, 'trntypdsc'),
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'gl_account_id' => $this->optionalRowValue($old, 'gldepcde'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('mf_inventory_transactiontypes')->insert($payload);
                            $transactionTypeRows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('mf_inventory_transactiontypes')->insert($payload);
                        $transactionTypeRows += count($payload);
                    }
                } else {
                    $transactionTypeSourceMissing = true;
                    $this->noteMissingSourceTable('trantypefile', $source, $target, $conversionNotes, 'inventory_transaction');

                    foreach ($targetDb->table('mf_inventory_transactiontypes')->pluck('transaction_type_id') as $transactionTypeId) {
                        $validTransactionTypeIds[(string) $transactionTypeId] = true;
                    }
                }

                $validBranchIds = $targetDb->table('mf_branch')->pluck('branch_id')->flip()->all();

                // 2. inventorytranfile1 → trn_inventory_transaction_file1
                if ($this->sourceTableExists($sourceDb, 'inventorytranfile1')) {
                    $payload = [];

                    foreach ($sourceDb->table('inventorytranfile1')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $documentNumber = trim((string) ($this->optionalRowValue($old, 'docnum') ?? ''));
                        $transactionTypeId = trim((string) ($this->optionalRowValue($old, 'trntypcde') ?? ''));

                        if ($documentNumber === '' || $transactionTypeId === '') {
                            $skippedInventoryFile1Rows++;

                            continue;
                        }

                        if (! isset($validTransactionTypeIds[$transactionTypeId])) {
                            $skippedInventoryFile1Rows++;
                            $note = "Skipped inventory transaction header \"{$documentNumber}\": transaction_type_id \"{$transactionTypeId}\" not found in mf_inventory_transactiontypes.";
                            $conversionNotes[] = $note;
                            Log::warning($note, [
                                'conversion' => 'inventory_transaction',
                                'document_number' => $documentNumber,
                                'transaction_type_id' => $transactionTypeId,
                                'source_database' => $source->database,
                                'target_database' => $target->database,
                            ]);

                            continue;
                        }

                        [$branchId, $branchNullified] = $this->resolveInventoryBranchId(
                            $sourceDb,
                            $targetDb,
                            $this->optionalRowValue($old, 'brhcde'),
                            $now,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "inventory transaction header \"{$documentNumber}\"",
                        );
                        if ($branchNullified) {
                            $nullifiedInventoryBranches++;
                        }

                        $row = [
                            'cancel_remarks' => $this->optionalRowValue($old, 'cancelrem'),
                            'document_number' => $documentNumber,
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'transaction_total' => $this->optionalRowValue($old, 'trntot'),
                            'user_id' => $this->optionalRowValue($old, 'usrnam'),
                            'log_time' => $this->optionalRowValue($old, 'logtim'),
                            'transaction_type_id' => $transactionTypeId,
                            'warehouse_id' => $this->optionalRowValue($old, 'warcde'),
                            'warehouse_id2' => $this->optionalRowValue($old, 'warcde2'),
                            'reference_number' => $this->optionalRowValue($old, 'refnum'),
                            'prepared_by' => $this->optionalRowValue($old, 'preby'),
                            'check_by' => $this->optionalRowValue($old, 'chkby'),
                            'approved_by' => $this->optionalRowValue($old, 'apvby'),
                            'cancel_document' => $this->optionalRowValue($old, 'canceldoc'),
                            'document_lock' => $this->optionalRowValue($old, 'doclock'),
                            'transaction_date' => $this->optionalRowValue($old, 'trndte'),
                            'log_date' => $this->optionalRowValue($old, 'logdte'),
                            'cancel_date' => $this->optionalRowValue($old, 'canceldte'),
                            'branch_id' => $branchId,
                            'gl_department_id' => $this->optionalRowValue($old, 'gldepcde'),
                            'deliver_status' => $this->optionalRowValue($old, 'delsta'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        for ($fieldIndex = 1; $fieldIndex <= 20; $fieldIndex++) {
                            $fieldKey = sprintf('field%02d', $fieldIndex);
                            $row[$fieldKey] = $this->optionalRowValue($old, $fieldKey);
                        }

                        $payload[] = $row;

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('trn_inventory_transaction_file1')->insert($payload);
                            $inventoryFile1Rows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('trn_inventory_transaction_file1')->insert($payload);
                        $inventoryFile1Rows += count($payload);
                    }
                } else {
                    $inventoryFile1SourceMissing = true;
                    $this->noteMissingSourceTable('inventorytranfile1', $source, $target, $conversionNotes, 'inventory_transaction');
                }

                // 3. inventorytranfile2 → trn_inventory_transaction_file2
                if ($this->sourceTableExists($sourceDb, 'inventorytranfile2')) {
                    $validItemIds = $targetDb->table('mf_items')->pluck('item_id')->flip()->all();
                    $payload = [];

                    foreach ($sourceDb->table('inventorytranfile2')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $documentNumber = trim((string) ($this->optionalRowValue($old, 'docnum') ?? ''));
                        $transactionTypeId = trim((string) ($this->optionalRowValue($old, 'trntypcde') ?? ''));
                        $itemId = trim((string) ($this->optionalRowValue($old, 'itmcde') ?? ''));

                        if ($documentNumber === '') {
                            $skippedInventoryFile2Rows++;

                            continue;
                        }

                        // if ($itemId === '' || ! isset($validItemIds[$itemId])) {
                        //     $skippedInventoryFile2Rows++;
                        //     $note = "Skipped inventory transaction detail for \"{$documentNumber}\": item_id \"{$itemId}\" not found in mf_items.";
                        //     $conversionNotes[] = $note;
                        //     Log::warning($note, [
                        //         'conversion' => 'inventory_transaction',
                        //         'document_number' => $documentNumber,
                        //         'item_id' => $itemId,
                        //         'source_database' => $source->database,
                        //         'target_database' => $target->database,
                        //     ]);

                        //     continue;
                        // }

                        if ($transactionTypeId === '' || ! isset($validTransactionTypeIds[$transactionTypeId])) {
                            $skippedInventoryFile2Rows++;
                            $note = "Skipped inventory transaction detail for \"{$documentNumber}\": transaction_type_id \"{$transactionTypeId}\" not found in mf_inventory_transactiontypes.";
                            $conversionNotes[] = $note;
                            Log::warning($note, [
                                'conversion' => 'inventory_transaction',
                                'document_number' => $documentNumber,
                                'transaction_type_id' => $transactionTypeId,
                                'source_database' => $source->database,
                                'target_database' => $target->database,
                            ]);

                            continue;
                        }

                        [$branchId, $branchNullified] = $this->resolveInventoryBranchId(
                            $sourceDb,
                            $targetDb,
                            $this->optionalRowValue($old, 'brhcde'),
                            $now,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "inventory transaction detail \"{$documentNumber}\" / \"{$itemId}\"",
                        );
                        if ($branchNullified) {
                            $nullifiedInventoryBranches++;
                        }

                        $row = [
                            'detail_type' => $this->optionalRowValue($old, 'dettyp'),
                            'document_number' => $documentNumber,
                            'item_id' => $itemId,
                            'item_quantity' => $this->optionalRowValue($old, 'itmqty'),
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'unit_of_measure' => $this->optionalRowValue($old, 'untmea'),
                            'factor' => $this->optionalRowValue($old, 'factor'),
                            'warehouse_id' => $this->optionalRowValue($old, 'warcde'),
                            'warehouse_id2' => $this->optionalRowValue($old, 'warcde2'),
                            'line_number' => $this->optionalRowValue($old, 'linenum'),
                            'unit_price' => $this->optionalRowValue($old, 'untprc'),
                            'extended_price' => $this->optionalRowValue($old, 'extprc'),
                            'log_time' => $this->optionalRowValue($old, 'logtim'),
                            'user_id' => $this->optionalRowValue($old, 'usrnam'),
                            'transaction_type_id' => $transactionTypeId,
                            'item_type' => $this->optionalRowValue($old, 'itmtyp'),
                            'item_remarks1' => $this->optionalRowValue($old, 'itmrem1'),
                            'item_remarks2' => $this->optionalRowValue($old, 'itmrem2'),
                            'item_remarks3' => $this->optionalRowValue($old, 'itmrem3'),
                            'transaction_date' => $this->optionalRowValue($old, 'trndte'),
                            'log_date' => $this->optionalRowValue($old, 'logdte'),
                            'barcode_number' => $this->optionalRowValue($old, 'barcodenum'),
                            'branch_id' => $branchId,
                            'barcode' => $this->optionalRowValue($old, 'barcde'),
                            'batch_number' => $this->optionalRowValue($old, 'batchnum'),
                            'manufacturing_date' => $this->optionalRowValue($old, 'mfgdte'),
                            'expiration_date' => $this->optionalRowValue($old, 'expdte'),
                            'copy_line_number' => $this->optionalRowValue($old, 'copyline'),
                            'deliver_status' => $this->optionalRowValue($old, 'delsta'),
                            'item_delivered' => $this->optionalRowValue($old, 'itmdel'),
                            'so_document_number' => $this->optionalRowValue($old, 'sonum'),
                            'warehouse_number' => 'MAIN',
                            'warehouse_location_id' => 'LSTVDEFAULTWHSLOCATION1',
                            'bin_number' => 'MAIN',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        for ($fieldIndex = 1; $fieldIndex <= 20; $fieldIndex++) {
                            $fieldKey = sprintf('field%02d', $fieldIndex);
                            $row[$fieldKey] = $this->optionalRowValue($old, $fieldKey);
                        }

                        $payload[] = $row;

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('trn_inventory_transaction_file2')->insert($payload);
                            $inventoryFile2Rows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('trn_inventory_transaction_file2')->insert($payload);
                        $inventoryFile2Rows += count($payload);
                    }
                } else {
                    $inventoryFile2SourceMissing = true;
                    $this->noteMissingSourceTable('inventorytranfile2', $source, $target, $conversionNotes, 'inventory_transaction');
                }

                $totalRows += $transactionTypeRows + $inventoryFile1Rows + $inventoryFile2Rows;

                $transferredTables[] = $transactionTypeSourceMissing
                    ? 'trantypefile → mf_inventory_transactiontypes (skipped, source table not found)'
                    : "trantypefile → mf_inventory_transactiontypes ({$transactionTypeRows} row(s))";

                $inventoryFile1Summary = $inventoryFile1SourceMissing
                    ? 'inventorytranfile1 → trn_inventory_transaction_file1 (skipped, source table not found)'
                    : "inventorytranfile1 → trn_inventory_transaction_file1 ({$inventoryFile1Rows} row(s))";
                if ($skippedInventoryFile1Rows > 0) {
                    $inventoryFile1Summary .= ", {$skippedInventoryFile1Rows} skipped";
                }
                if ($nullifiedInventoryBranches > 0) {
                    $inventoryFile1Summary .= ", {$nullifiedInventoryBranches} invalid branch(es) set to null";
                }
                $transferredTables[] = $inventoryFile1Summary;

                $inventoryFile2Summary = $inventoryFile2SourceMissing
                    ? 'inventorytranfile2 → trn_inventory_transaction_file2 (skipped, source table not found)'
                    : "inventorytranfile2 → trn_inventory_transaction_file2 ({$inventoryFile2Rows} row(s))";
                if ($skippedInventoryFile2Rows > 0) {
                    $inventoryFile2Summary .= ", {$skippedInventoryFile2Rows} skipped";
                }
                $transferredTables[] = $inventoryFile2Summary;
            }
            #endregion

            #region Physical Count Conversion
            if ($physicalCount) {
                $chunkSize = 500;
                $companyId = trim((string) $request->input('company_name', ''));
                $ensuredBranchIds = [];
                $validUserIds = $targetDb->table('users')->pluck('user_id')->flip()->all();
                $ensuredUserIds = [];
                $createdPhysicalCountUsers = 0;
                $nullifiedPhysicalCountUsers = 0;
                $physicalCountFile1Rows = 0;
                $physicalCountFile3Rows = 0;
                $physicalCountFile31Rows = 0;
                $physicalCountFile2Rows = 0;
                $skippedPhysicalCountFile1Rows = 0;
                $skippedPhysicalCountFile3Rows = 0;
                $skippedPhysicalCountFile31Rows = 0;
                $skippedPhysicalCountFile2Rows = 0;
                $nullifiedPhysicalCountBranches = 0;
                $physicalCountFile1SourceMissing = false;
                $physicalCountFile3SourceMissing = false;
                $physicalCountFile31SourceMissing = false;
                $physicalCountFile2SourceMissing = false;

                $hasPhysicalCountSource = $this->sourceTableExists($sourceDb, 'physicalcountfile1')
                    || $this->sourceTableExists($sourceDb, 'physicalcountfile2')
                    || $this->sourceTableExists($sourceDb, 'physicalcountfile3')
                    || $this->sourceTableExists($sourceDb, 'physicalcountfile31');

                if ($hasPhysicalCountSource) {
                    $targetDb->statement('SET FOREIGN_KEY_CHECKS = 0');
                    $targetDb->table('trn_physical_count_file2')->delete();
                    $targetDb->table('trn_physical_count_file31')->delete();
                    $targetDb->table('trn_physical_count_file3')->delete();
                    $targetDb->table('trn_physical_count_file1')->delete();
                    $targetDb->statement('SET FOREIGN_KEY_CHECKS = 1');
                }

                $validBranchIds = $targetDb->table('mf_branch')->pluck('branch_id')->flip()->all();
                $validItemIds = $targetDb->table('mf_items')->pluck('item_id')->flip()->all();
                $warehouseDefaults = [
                    'warehouse_number' => 'MAIN',
                    'warehouse_location_id' => 'LSTVDEFAULTWHSLOCATION1',
                    'bin_number' => 'MAIN',
                ];

                // 1. physicalcountfile1 → trn_physical_count_file1
                if ($this->sourceTableExists($sourceDb, 'physicalcountfile1')) {
                    $payload = [];

                    foreach ($sourceDb->table('physicalcountfile1')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $documentNumber = trim((string) ($this->optionalRowValue($old, 'docnum') ?? ''));
                        if ($documentNumber === '') {
                            $skippedPhysicalCountFile1Rows++;

                            continue;
                        }

                        [$branchId, $branchNullified] = $this->resolveInventoryBranchId(
                            $sourceDb,
                            $targetDb,
                            $this->optionalRowValue($old, 'brhcde'),
                            $now,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count header \"{$documentNumber}\"",
                        );
                        if ($branchNullified) {
                            $nullifiedPhysicalCountBranches++;
                        }

                        $rawUserId = trim((string) ($this->optionalRowValue($old, 'usrnam') ?? ''));
                        [$resolvedUserId, $userWasCreated] = $this->ensureUserExistsFromSource(
                            $sourceDb,
                            $targetDb,
                            $rawUserId,
                            $now,
                            $companyId,
                            $validUserIds,
                            $ensuredUserIds,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count header \"{$documentNumber}\"",
                        );
                        if ($userWasCreated) {
                            $createdPhysicalCountUsers++;
                        }
                        if ($rawUserId !== '' && $resolvedUserId === null) {
                            $nullifiedPhysicalCountUsers++;
                        }

                        $payload[] = [
                            'document_number' => $documentNumber,
                            'disable_inv_gain_loss' => $this->optionalRowValue($old, 'disinvgl'),
                            'transaction_date' => $this->optionalRowValue($old, 'trndte'),
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'transaction_type_code' => $this->optionalRowValue($old, 'trntypcde'),
                            'remarks' => $this->optionalRowValue($old, 'remarks'),
                            'warehouse_id' => $this->optionalRowValue($old, 'warcde'),
                            'branch_id' => $branchId,
                            'gl_department_id' => $this->optionalRowValue($old, 'gldepcde'),
                            'user_id' => $resolvedUserId,
                            'log_date' => $this->optionalRowValue($old, 'logdte'),
                            'log_time' => $this->optionalRowValue($old, 'logtim'),
                            'status' => $this->optionalRowValue($old, 'status'),
                            'document_lock' => $this->optionalRowValue($old, 'doclock'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('trn_physical_count_file1')->insert($payload);
                            $physicalCountFile1Rows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('trn_physical_count_file1')->insert($payload);
                        $physicalCountFile1Rows += count($payload);
                    }
                } else {
                    $physicalCountFile1SourceMissing = true;
                    $this->noteMissingSourceTable('physicalcountfile1', $source, $target, $conversionNotes, 'physical_count');
                }

                // 2. physicalcountfile3 → trn_physical_count_file3
                if ($this->sourceTableExists($sourceDb, 'physicalcountfile3')) {
                    $payload = [];

                    foreach ($sourceDb->table('physicalcountfile3')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $documentNumber = trim((string) ($this->optionalRowValue($old, 'docnum') ?? ''));
                        $itemId = trim((string) ($this->optionalRowValue($old, 'itmcde') ?? ''));

                        if ($documentNumber === '') {
                            $skippedPhysicalCountFile3Rows++;

                            continue;
                        }

                        if ($itemId === '' || ! isset($validItemIds[$itemId])) {
                            $skippedPhysicalCountFile3Rows++;
                            $note = "Skipped physical count line (file3) for \"{$documentNumber}\": item_id \"{$itemId}\" not found in mf_items.";
                            $conversionNotes[] = $note;
                            Log::warning($note, [
                                'conversion' => 'physical_count',
                                'document_number' => $documentNumber,
                                'item_id' => $itemId,
                                'source_database' => $source->database,
                                'target_database' => $target->database,
                            ]);

                            continue;
                        }

                        [$branchId, $branchNullified] = $this->resolveInventoryBranchId(
                            $sourceDb,
                            $targetDb,
                            $this->optionalRowValue($old, 'brhcde'),
                            $now,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count line (file3) \"{$documentNumber}\" / \"{$itemId}\"",
                        );
                        if ($branchNullified) {
                            $nullifiedPhysicalCountBranches++;
                        }

                        $rawUserId = trim((string) ($this->optionalRowValue($old, 'usrnam') ?? ''));
                        [$resolvedUserId, $userWasCreated] = $this->ensureUserExistsFromSource(
                            $sourceDb,
                            $targetDb,
                            $rawUserId,
                            $now,
                            $companyId,
                            $validUserIds,
                            $ensuredUserIds,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count line (file3) \"{$documentNumber}\" / \"{$itemId}\"",
                        );
                        if ($userWasCreated) {
                            $createdPhysicalCountUsers++;
                        }
                        if ($rawUserId !== '' && $resolvedUserId === null) {
                            $nullifiedPhysicalCountUsers++;
                        }

                        $payload[] = array_merge([
                            'reference_number' => $this->optionalRowValue($old, 'refnum'),
                            'document_number' => $documentNumber,
                            'item_id' => $itemId,
                            'item_quantity' => $this->optionalRowValue($old, 'itmqty'),
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'unit_of_measure' => $this->optionalRowValue($old, 'untmea'),
                            'factor' => $this->optionalRowValue($old, 'factor'),
                            'warehouse_id' => $this->optionalRowValue($old, 'warcde'),
                            'unit_price' => $this->optionalRowValue($old, 'untprc'),
                            'extended_price' => $this->optionalRowValue($old, 'extprc'),
                            'gross_price' => $this->optionalRowValue($old, 'groprc'),
                            'user_id' => $resolvedUserId,
                            'string_item_quantity' => $this->optionalRowValue($old, 'stritmqty'),
                            'pstr_item_quantity' => $this->optionalRowValue($old, 'pstritmqty'),
                            'line_number' => $this->optionalRowValue($old, 'linenum'),
                            'log_time' => $this->optionalRowValue($old, 'logtim'),
                            'transaction_date' => $this->optionalRowValue($old, 'trndte'),
                            'log_date' => $this->optionalRowValue($old, 'logdte'),
                            'item_type' => $this->optionalRowValue($old, 'itmtyp'),
                            'branch_id' => $branchId,
                            'tag_number' => $this->optionalRowValue($old, 'tagnum'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $warehouseDefaults);

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('trn_physical_count_file3')->insert($payload);
                            $physicalCountFile3Rows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('trn_physical_count_file3')->insert($payload);
                        $physicalCountFile3Rows += count($payload);
                    }
                } else {
                    $physicalCountFile3SourceMissing = true;
                    $this->noteMissingSourceTable('physicalcountfile3', $source, $target, $conversionNotes, 'physical_count');
                }

                // 3. physicalcountfile31 → trn_physical_count_file31
                if ($this->sourceTableExists($sourceDb, 'physicalcountfile31')) {
                    $payload = [];

                    foreach ($sourceDb->table('physicalcountfile31')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $documentNumber = trim((string) ($this->optionalRowValue($old, 'docnum') ?? ''));
                        if ($documentNumber === '') {
                            $skippedPhysicalCountFile31Rows++;

                            continue;
                        }

                        [$branchId, $branchNullified] = $this->resolveInventoryBranchId(
                            $sourceDb,
                            $targetDb,
                            $this->optionalRowValue($old, 'brhcde'),
                            $now,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count sub-header \"{$documentNumber}\"",
                        );
                        if ($branchNullified) {
                            $nullifiedPhysicalCountBranches++;
                        }

                        $rawUserId = trim((string) ($this->optionalRowValue($old, 'usrnam') ?? ''));
                        [$resolvedUserId, $userWasCreated] = $this->ensureUserExistsFromSource(
                            $sourceDb,
                            $targetDb,
                            $rawUserId,
                            $now,
                            $companyId,
                            $validUserIds,
                            $ensuredUserIds,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count sub-header \"{$documentNumber}\"",
                        );
                        if ($userWasCreated) {
                            $createdPhysicalCountUsers++;
                        }
                        if ($rawUserId !== '' && $resolvedUserId === null) {
                            $nullifiedPhysicalCountUsers++;
                        }

                        $payload[] = [
                            'reference_number' => $this->optionalRowValue($old, 'refnum'),
                            'document_number' => $documentNumber,
                            'remarks' => $this->optionalRowValue($old, 'remarks'),
                            'user_id' => $resolvedUserId,
                            'document_lock' => $this->optionalRowValue($old, 'doclock'),
                            'warehouse_id' => $this->optionalRowValue($old, 'warcde'),
                            'branch_id' => $branchId,
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'transaction_date' => $this->optionalRowValue($old, 'trndte'),
                            'transaction_total' => $this->optionalRowValue($old, 'trntot'),
                            'log_date' => $this->optionalRowValue($old, 'logdte'),
                            'log_time' => $this->optionalRowValue($old, 'logtim'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('trn_physical_count_file31')->insert($payload);
                            $physicalCountFile31Rows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('trn_physical_count_file31')->insert($payload);
                        $physicalCountFile31Rows += count($payload);
                    }
                } else {
                    $physicalCountFile31SourceMissing = true;
                    $this->noteMissingSourceTable('physicalcountfile31', $source, $target, $conversionNotes, 'physical_count');
                }

                // 4. physicalcountfile2 → trn_physical_count_file2
                if ($this->sourceTableExists($sourceDb, 'physicalcountfile2')) {
                    $payload = [];

                    foreach ($sourceDb->table('physicalcountfile2')->orderBy('recid')->lazy($chunkSize) as $old) {
                        $documentNumber = trim((string) ($this->optionalRowValue($old, 'docnum') ?? ''));
                        $itemId = trim((string) ($this->optionalRowValue($old, 'itmcde') ?? ''));

                        if ($documentNumber === '') {
                            $skippedPhysicalCountFile2Rows++;

                            continue;
                        }

                        if ($itemId === '' || ! isset($validItemIds[$itemId])) {
                            $skippedPhysicalCountFile2Rows++;
                            $note = "Skipped physical count line (file2) for \"{$documentNumber}\": item_id \"{$itemId}\" not found in mf_items.";
                            $conversionNotes[] = $note;
                            Log::warning($note, [
                                'conversion' => 'physical_count',
                                'document_number' => $documentNumber,
                                'item_id' => $itemId,
                                'source_database' => $source->database,
                                'target_database' => $target->database,
                            ]);

                            continue;
                        }

                        $rawUserId = trim((string) ($this->optionalRowValue($old, 'usrnam') ?? ''));
                        [$resolvedUserId, $userWasCreated] = $this->ensureUserExistsFromSource(
                            $sourceDb,
                            $targetDb,
                            $rawUserId,
                            $now,
                            $companyId,
                            $validUserIds,
                            $ensuredUserIds,
                            $validBranchIds,
                            $ensuredBranchIds,
                            $conversionNotes,
                            "physical count line (file2) \"{$documentNumber}\" / \"{$itemId}\"",
                        );
                        if ($userWasCreated) {
                            $createdPhysicalCountUsers++;
                        }
                        if ($rawUserId !== '' && $resolvedUserId === null) {
                            $nullifiedPhysicalCountUsers++;
                        }

                        $payload[] = array_merge([
                            'document_number' => $documentNumber,
                            'item_id' => $itemId,
                            'transaction_code' => $this->optionalRowValue($old, 'trncde'),
                            'unit_of_measure' => $this->optionalRowValue($old, 'untmea'),
                            'warehouse_id' => $this->optionalRowValue($old, 'warcde'),
                            'line_number' => $this->optionalRowValue($old, 'linenum'),
                            'tag_number' => $this->optionalRowValue($old, 'tagnum'),
                            'gross_price' => $this->optionalRowValue($old, 'groprc'),
                            'unit_price' => $this->optionalRowValue($old, 'untprc'),
                            'item_quantity' => $this->optionalRowValue($old, 'itmqty'),
                            'extended_price' => $this->optionalRowValue($old, 'extprc'),
                            'log_time' => $this->optionalRowValue($old, 'logtim'),
                            'user_id' => $resolvedUserId,
                            'string_item_quantity' => $this->optionalRowValue($old, 'stritmqty'),
                            'pstr_item_quantity' => $this->optionalRowValue($old, 'pstritmqty'),
                            'item_type' => $this->optionalRowValue($old, 'itmtyp'),
                            'item_remarks1' => $this->optionalRowValue($old, 'itmrem1'),
                            'item_remarks2' => $this->optionalRowValue($old, 'itmrem2'),
                            'item_remarks3' => $this->optionalRowValue($old, 'itmrem3'),
                            'transaction_date' => $this->optionalRowValue($old, 'trndte'),
                            'log_date' => $this->optionalRowValue($old, 'logdte'),
                            'factor' => $this->optionalRowValue($old, 'factor'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ], $warehouseDefaults);

                        if (count($payload) >= $chunkSize) {
                            $targetDb->table('trn_physical_count_file2')->insert($payload);
                            $physicalCountFile2Rows += count($payload);
                            $payload = [];
                        }
                    }

                    if ($payload !== []) {
                        $targetDb->table('trn_physical_count_file2')->insert($payload);
                        $physicalCountFile2Rows += count($payload);
                    }
                } else {
                    $physicalCountFile2SourceMissing = true;
                    $this->noteMissingSourceTable('physicalcountfile2', $source, $target, $conversionNotes, 'physical_count');
                }

                $totalRows += $physicalCountFile1Rows + $physicalCountFile3Rows + $physicalCountFile31Rows + $physicalCountFile2Rows;

                $physicalCountFile1Summary = $physicalCountFile1SourceMissing
                    ? 'physicalcountfile1 → trn_physical_count_file1 (skipped, source table not found)'
                    : "physicalcountfile1 → trn_physical_count_file1 ({$physicalCountFile1Rows} row(s))";
                if ($skippedPhysicalCountFile1Rows > 0) {
                    $physicalCountFile1Summary .= ", {$skippedPhysicalCountFile1Rows} skipped";
                }
                if ($nullifiedPhysicalCountBranches > 0) {
                    $physicalCountFile1Summary .= ", {$nullifiedPhysicalCountBranches} invalid branch(es) set to null";
                }
                if ($createdPhysicalCountUsers > 0) {
                    $physicalCountFile1Summary .= ", {$createdPhysicalCountUsers} user(s) auto-created in users";
                }
                if ($nullifiedPhysicalCountUsers > 0) {
                    $physicalCountFile1Summary .= ", {$nullifiedPhysicalCountUsers} user_id(s) set to null";
                }
                $transferredTables[] = $physicalCountFile1Summary;

                $physicalCountFile3Summary = $physicalCountFile3SourceMissing
                    ? 'physicalcountfile3 → trn_physical_count_file3 (skipped, source table not found)'
                    : "physicalcountfile3 → trn_physical_count_file3 ({$physicalCountFile3Rows} row(s))";
                if ($skippedPhysicalCountFile3Rows > 0) {
                    $physicalCountFile3Summary .= ", {$skippedPhysicalCountFile3Rows} skipped";
                }
                $transferredTables[] = $physicalCountFile3Summary;

                $transferredTables[] = $physicalCountFile31SourceMissing
                    ? 'physicalcountfile31 → trn_physical_count_file31 (skipped, source table not found)'
                    : "physicalcountfile31 → trn_physical_count_file31 ({$physicalCountFile31Rows} row(s))".($skippedPhysicalCountFile31Rows > 0 ? ", {$skippedPhysicalCountFile31Rows} skipped" : '');

                $physicalCountFile2Summary = $physicalCountFile2SourceMissing
                    ? 'physicalcountfile2 → trn_physical_count_file2 (skipped, source table not found)'
                    : "physicalcountfile2 → trn_physical_count_file2 ({$physicalCountFile2Rows} row(s))";
                if ($skippedPhysicalCountFile2Rows > 0) {
                    $physicalCountFile2Summary .= ", {$skippedPhysicalCountFile2Rows} skipped";
                }
                $transferredTables[] = $physicalCountFile2Summary;
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

    /**
     * @param  array<string, true>  $validBranchIds
     * @param  array<string, true>  $ensuredBranchIds
     * @param  list<string>  $conversionNotes
     * @return array{0: ?string, 1: bool}
     */
    protected function resolveInventoryBranchId(
        mixed $sourceDb,
        mixed $targetDb,
        mixed $branchId,
        mixed $now,
        array &$validBranchIds,
        array &$ensuredBranchIds,
        array &$conversionNotes,
        string $contextLabel,
    ): array {
        $branchId = trim((string) ($branchId ?? ''));
        if ($branchId === '') {
            return [null, false];
        }

        if (! isset($validBranchIds[$branchId])) {
            $this->ensureBranchExists($sourceDb, $targetDb, $branchId, $now, $ensuredBranchIds, $conversionNotes);

            if ($targetDb->table('mf_branch')->where('branch_id', $branchId)->exists()) {
                $validBranchIds[$branchId] = true;
            }
        }

        if (! isset($validBranchIds[$branchId])) {
            $note = "Set branch_id to null for {$contextLabel}: branch \"{$branchId}\" not found in mf_branch.";
            $conversionNotes[] = $note;
            Log::warning($note, [
                'conversion' => 'inventory_transaction',
                'branch_id' => $branchId,
            ]);

            return [null, true];
        }

        return [$branchId, false];
    }

    /**
     * @param  array<string, true>  $validUserIds
     * @param  array<string, true>  $ensuredUserIds
     * @param  array<string, true>  $validBranchIds
     * @param  array<string, true>  $ensuredBranchIds
     * @param  list<string>  $conversionNotes
     * @return array{0: ?string, 1: bool}
     */
    protected function ensureUserExistsFromSource(
        mixed $sourceDb,
        mixed $targetDb,
        mixed $userId,
        mixed $now,
        string $defaultFirstName,
        array &$validUserIds,
        array &$ensuredUserIds,
        array &$validBranchIds,
        array &$ensuredBranchIds,
        array &$conversionNotes,
        string $contextLabel,
        string $conversion = 'physical_count',
    ): array {
        $userId = trim((string) ($userId ?? ''));
        if ($userId === '') {
            return [null, false];
        }

        if (isset($validUserIds[$userId])) {
            return [$userId, false];
        }

        if (isset($ensuredUserIds[$userId])) {
            return [null, false];
        }

        $ensuredUserIds[$userId] = true;

        if ($targetDb->table('users')->where('user_id', $userId)->exists()) {
            $validUserIds[$userId] = true;

            return [$userId, false];
        }

        if (! $this->sourceTableExists($sourceDb, 'users')) {
            $note = "Set user_id to null for {$contextLabel}: user \"{$userId}\" not in target users and source users table not found.";
            $conversionNotes[] = $note;
            Log::warning($note, [
                'conversion' => $conversion,
                'user_id' => $userId,
            ]);

            return [null, false];
        }

        $old = $sourceDb->table('users')->where('usrcde', $userId)->first();
        if ($old === null) {
            $note = "Set user_id to null for {$contextLabel}: user \"{$userId}\" not found in source users table.";
            $conversionNotes[] = $note;
            Log::warning($note, [
                'conversion' => $conversion,
                'user_id' => $userId,
            ]);

            return [null, false];
        }

        $lastBranch = trim((string) ($old->lastbrnch ?? ''));
        $lastUsedBranchId = null;
        if ($lastBranch !== '') {
            if (! isset($validBranchIds[$lastBranch])) {
                $this->ensureBranchExists($sourceDb, $targetDb, $lastBranch, $now, $ensuredBranchIds, $conversionNotes);

                if ($targetDb->table('mf_branch')->where('branch_id', $lastBranch)->exists()) {
                    $validBranchIds[$lastBranch] = true;
                }
            }

            if (isset($validBranchIds[$lastBranch])) {
                $lastUsedBranchId = $lastBranch;
            }
        }

        $userType = trim((string) ($this->optionalRowValue($old, 'usrlvl') ?? ''));
        $userType = $userType === '' ? 'User' : $userType;
        $firstName = $defaultFirstName !== '' ? $defaultFirstName : $userId;

        $targetDb->table('users')->upsert(
            [[
                'user_id' => $userId,
                'username' => $userId,
                'last_name' => $userId,
                'password' => $this->optionalRowValue($old, 'usrpwd'),
                'user_type' => $userType,
                'first_name' => $firstName,
                'last_used_branch_id' => $lastUsedBranchId,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id'],
            ['username', 'last_name', 'password', 'user_type', 'first_name', 'last_used_branch_id', 'updated_at'],
        );

        $validUserIds[$userId] = true;

        $note = "Auto-created user \"{$userId}\" in users table from source users for {$contextLabel}.";
        $conversionNotes[] = $note;
        Log::info($note, [
            'conversion' => $conversion,
            'user_id' => $userId,
        ]);

        return [$userId, true];
    }

    protected function sourceTableExists(mixed $db, string $table): bool
    {
        try {
            return $db->getSchemaBuilder()->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    protected function noteMissingSourceTable(
        string $sourceTable,
        RemoteDatabaseConfig $source,
        RemoteDatabaseConfig $target,
        array &$conversionNotes,
        string $conversion = 'user_file',
    ): void {
        $note = "Skipped conversion for \"{$sourceTable}\": source table not found in {$source->database}. Target data was left unchanged.";
        $conversionNotes[] = $note;
        Log::warning($note, [
            'conversion' => $conversion,
            'source_table' => $sourceTable,
            'source_database' => $source->database,
            'target_database' => $target->database,
        ]);
    }

    protected function normalizeLookupKey(string $value, array $aliases = []): string
    {
        $normalized = strtolower(trim($value));

        foreach ($aliases as $from => $to)
        {
            if ($normalized === strtolower($from))
            {
                $normalized = strtolower($to);
                break;
            }
        }

        return $normalized;
    }

    protected function upsertChunked(mixed $targetDb,string $table, array $payload, array $uniqueBy, array $updateColumns): int
    {
        if ($payload === [])
        {
            return 0;
        }

        $targetDb->table($table)->upsert($payload, $uniqueBy, $updateColumns);

        return count($payload);
    }

    /**
     * @param  array<string, true|int>  $validIds
     */
    protected function resolveForeignKeyId(mixed $value, array $validIds, ?int &$nullifiedCount = null): ?string
    {
        $id = trim((string) ($value ?? ''));
        if ($id === '') {
            return null;
        }

        if (isset($validIds[$id])) {
            return $id;
        }

        if ($nullifiedCount !== null) {
            $nullifiedCount++;
        }

        return null;
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
