@extends('layouts.app')

@section('title', 'Database transfer')

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-900">PHP Central To Laravel Central Data Conversion Utility</h1>
        <p class="mt-1 text-sm text-zinc-600">Move data from an old database to a new one on the same localhost server.</p>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-white shadow-sm">
        <nav id="wizard-stepper" class="border-b border-zinc-200 px-4 py-6 sm:px-8" aria-label="Progress">
            <ol class="flex items-center w-full max-w-3xl mx-auto">
                <li class="wizard-step flex flex-1 flex-col items-center gap-2" data-step="1">
                    <div class="wizard-step-circle flex h-10 w-10 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors">
                        1
                    </div>
                    <span class="wizard-step-label text-xs font-medium text-center sm:text-sm">Connection</span>
                </li>
                <li class="wizard-step-line mx-2 h-0.5 flex-1 bg-zinc-200 sm:mx-4" aria-hidden="true"></li>
                <li class="wizard-step flex flex-1 flex-col items-center gap-2" data-step="2">
                    <div class="wizard-step-circle flex h-10 w-10 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors">
                        2
                    </div>
                    <span class="wizard-step-label text-xs font-medium text-center sm:text-sm">Databases</span>
                </li>
                <li class="wizard-step-line mx-2 h-0.5 flex-1 bg-zinc-200 sm:mx-4" aria-hidden="true"></li>
                <li class="wizard-step flex flex-1 flex-col items-center gap-2" data-step="3">
                    <div class="wizard-step-circle flex h-10 w-10 items-center justify-center rounded-full border-2 text-sm font-semibold transition-colors">
                        3
                    </div>
                    <span class="wizard-step-label text-xs font-medium text-center sm:text-sm">Data conversion</span>
                </li>
            </ol>
        </nav>

        <form id="transfer-form"
                class="p-4 sm:p-8"
                data-route-test="{{ route('utilities.test-connection') }}"
                data-route-run="{{ route('utilities.run') }}"
                onsubmit="return false;">
                {{-- Step 1 --}}
                <section id="step-panel-1" class="step-panel space-y-5">
                    <div>
                        <h2 class="text-lg font-medium text-zinc-900">Step 1 — Localhost connection</h2>
                        <p class="mt-1 text-sm text-zinc-600">Enter shared server credentials, then test the connection.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="driver">Driver</label>
                            <select id="driver" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                                <option value="mysql" selected>mysql</option>
                                {{-- <option value="mariadb">mariadb</option>
                                <option value="pgsql">pgsql</option>
                                <option value="sqlsrv">sqlsrv</option> --}}
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="host">Host</label>
                            <input id="host" type="text" value="127.0.0.1" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="port">Port</label>
                            <input id="port" type="number" value="3306" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="username">Username</label>
                            <input id="username" type="text" value="root" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="password">Password</label>
                            <input id="password" type="password" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="charset">Charset (optional)</label>
                            <input id="charset" type="text" placeholder="utf8mb4" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2 border-t border-zinc-100 pt-5">
                        <button type="button" id="load-default-connection"
                                class="rounded-md border border-zinc-300 bg-zinc-50 px-4 py-2 text-sm font-medium hover:bg-zinc-100">
                            Load default
                        </button>
                        <button type="button" id="test-connection"
                                class="rounded-md bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-800">
                            Test connection
                        </button>
                    </div>
                </section>

                {{-- Step 2 --}}
                <section id="step-panel-2" class="step-panel hidden space-y-5">
                    <div>
                        <h2 class="text-lg font-medium text-zinc-900">Step 2 — Select databases</h2>
                        <p class="mt-1 text-sm text-zinc-600">Choose the source (old) and target (new) database names.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 max-w-2xl">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="source_database">Source database</label>
                            <select id="source_database" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                                <option value="">Select database</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="target_database">Target database</label>
                            <select id="target_database" class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500">
                                <option value="">Select database</option>
                            </select>
                        </div>
                    </div>

                    <p id="database-pair-hint" class="hidden rounded-lg bg-zinc-50 px-4 py-3 text-sm text-zinc-600">
                        Selected:
                        <span id="pair-source" class="font-mono font-medium text-zinc-900"></span>
                        →
                        <span id="pair-target" class="font-mono font-medium text-zinc-900"></span>
                    </p>

                    <div class="flex flex-wrap gap-2 border-t border-zinc-100 pt-5">
                        <button type="button" id="back-to-step-1"
                                class="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50">
                            ← Back
                        </button>
                        <button type="button" id="continue-btn" disabled
                                class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">
                            Continue to conversion
                        </button>
                    </div>
                </section>

                {{-- Step 3 --}}
                <section id="step-panel-3" class="step-panel hidden space-y-5">
                    <div>
                        <h2 class="text-lg font-medium text-zinc-900">Step 3 — Data conversion</h2>
                        <p class="mt-1 text-sm text-zinc-600">Choose conversions to run. Each conversion is a service under <code class="text-xs bg-zinc-100 px-1 rounded">app/Services/Conversions</code>.</p>
                    </div>

                    <div class="max-w-md">
                        <label class="mb-1 block text-sm font-medium text-zinc-900" for="company_name">Company ID</label>
                        <input type="text" id="company_name" name="company_name"
                               class="w-full rounded-md border border-zinc-300 px-3 py-2 text-sm font-mono focus:border-zinc-500 focus:outline-none focus:ring-1 focus:ring-zinc-500"
                               placeholder="Enter company ID manually (company file + users first_name)">
                    </div>

                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                        <span id="conversion-source" class="font-mono font-semibold"></span>
                        →
                        <span id="conversion-target" class="font-mono font-semibold"></span>
                    </div>

                    <div class="space-y-2 rounded-lg border border-zinc-200 p-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Conversions</p>

                        @foreach ([
                            ['id' => 'company_file', 'label' => 'Company file', 'hint' => 'companyfile → companyfile'],
                            ['id' => 'system_parameter', 'label' => 'System Parameter', 'hint' => 'syspar → sys_setup'],
                            ['id' => 'branch', 'label' => 'Branch', 'hint' => 'branchfile → mf_branch'],
                            ['id' => 'user_file', 'label' => 'User File', 'hint' => 'pos_userfile → mf_pos_users + users'],
                            ['id' => 'currency', 'label' => 'Currency', 'hint' => 'currencyfile → currency_file'],
                            ['id' => 'tax_code', 'label' => 'Tax Code', 'hint' => null],
                            ['id' => 'item_classification', 'label' => 'Item Classification', 'hint' => null],
                            ['id' => 'item_sub_class', 'label' => 'Item Sub Class', 'hint' => null],
                            ['id' => 'warehouse', 'label' => 'Warehouse', 'hint' => null],
                            ['id' => 'dine_type', 'label' => 'Dine Type', 'hint' => null],
                            ['id' => 'card_type', 'label' => 'Card Type', 'hint' => 'cardtypefile → mf_cardtypes'],
                            ['id' => 'memc', 'label' => 'MEMC', 'hint' => 'memcfile → mf_memcfile'],
                            ['id' => 'other_payments', 'label' => 'Other Payments', 'hint' => null],
                            ['id' => 'item', 'label' => 'Item', 'hint' => null],
                            ['id' => 'discount', 'label' => 'Discount', 'hint' => null],
                            ['id' => 'special_request', 'label' => 'Special Request', 'hint' => null],
                            ['id' => 'free_reason', 'label' => 'Free Reason', 'hint' => null],
                            ['id' => 'void_reason', 'label' => 'Void Reason', 'hint' => null],
                            ['id' => 'cash_in_out_reason', 'label' => 'Cash In/Out Reason', 'hint' => null],
                            ['id' => 'price_list', 'label' => 'Price List', 'hint' => null],
                            ['id' => 'inventory_transaction', 'label' => 'Inventory Transaction', 'hint' => null],
                            ['id' => 'physical_count', 'label' => 'Physical Count', 'hint' => null],
                        ] as $option)
                            <label class="flex items-start gap-3 rounded-md px-2 py-2 cursor-pointer hover:bg-zinc-50">
                                <input type="checkbox" id="{{ $option['id'] }}" value="1" class="conversion-option mt-0.5 h-4 w-4 rounded border-zinc-300 text-emerald-600">
                                <span>
                                    <span class="text-sm font-medium text-zinc-900">{{ $option['label'] }}</span>
                                    @if ($option['hint'])
                                        <span class="mt-0.5 block text-xs text-zinc-500 font-mono">{{ $option['hint'] }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach

                    </div>

                    <div class="flex flex-wrap gap-2 border-t border-zinc-100 pt-5">
                        <button type="button" id="back-to-step-2"
                                class="rounded-md border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50">
                            ← Back
                        </button>
                        <button type="button" id="convert-btn"
                                class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500">
                            Convert data
                        </button>
                    </div>
                </section>
        </form>
    </div>

    <style>
        .wizard-step-circle { border-color: #d4d4d8; background: #fff; color: #71717a; }
        .wizard-step-label { color: #71717a; }
        .wizard-step-line { background: #e4e4e7; }
        .wizard-step.is-active .wizard-step-circle {
            border-color: #18181b; background: #18181b; color: #fff;
        }
        .wizard-step.is-active .wizard-step-label { color: #18181b; font-weight: 600; }
        .wizard-step.is-complete .wizard-step-circle {
            border-color: #059669; background: #059669; color: #fff;
        }
        .wizard-step.is-complete .wizard-step-label { color: #047857; }
        .wizard-step.is-complete + .wizard-step-line,
        .wizard-step-line.is-complete { background: #059669; }
    </style>
@endsection
