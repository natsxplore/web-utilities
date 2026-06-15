import $ from 'jquery';
import Swal from 'sweetalert2';

export function initTransferWizard() {
    const $form = $('#transfer-form');

    if (!$form.length) {
        return;
    }

    const routes = {
        test: $form.data('route-test'),
        run: $form.data('route-run'),
    };

    const defaultConnection = {
        driver: 'mysql',
        host: '127.0.0.1',
        port: '3306',
        username: 'root',
        password: 'Lstv@2016',
        charset: '',
    };

    const $connectionFields = $('#driver, #host, #port, #username, #password, #charset');
    const $databaseFields = $('#source_database, #target_database');
    const $sourceDatabase = $('#source_database');
    const $targetDatabase = $('#target_database');
    const $continueBtn = $('#continue-btn');
    const $databasePairHint = $('#database-pair-hint');
    const $conversionOptions = $('.conversion-option');

    let currentStep = 1;

    function goToStep(step) {
        currentStep = step;

        $('.step-panel').addClass('hidden');
        $(`#step-panel-${step}`).removeClass('hidden');

        $('.wizard-step').each(function () {
            const $step = $(this);
            const n = Number($step.data('step'));
            $step.removeClass('is-active is-complete');
            if (n === step) {
                $step.addClass('is-active');
            } else if (n < step) {
                $step.addClass('is-complete');
            }
        });

        $('.wizard-step-line').each(function (index) {
            $(this).toggleClass('is-complete', index < step - 1);
        });

        setStepFieldsLocked(step === 3);
    }

    function setStepFieldsLocked(locked) {
        $connectionFields.prop('disabled', locked);
        $databaseFields.prop('disabled', locked);
        $('#load-default-connection, #test-connection').prop('disabled', locked);
    }

    function loadDefaultConnection() {
        if (currentStep === 3) {
            return;
        }

        Object.entries(defaultConnection).forEach(([field, value]) => {
            $(`#${field}`).val(value);
        });
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'success',
            showConfirmButton: false,
            timer: 3000,
            title: 'Default localhost connection applied.',
        });
    }

    function connectionPayload() {
        return {
            driver: $('#driver').val(),
            host: $('#host').val(),
            port: $('#port').val(),
            username: $('#username').val(),
            password: $('#password').val(),
            charset: $('#charset').val() || null,
        };
    }

    function hasConversionSelected() {
        return $conversionOptions.filter(':checked').length > 0;
    }

    function resetConversionOptions() {
        $conversionOptions.prop('checked', false);
    }

    function fullPayload() {
        const payload = $.extend({}, connectionPayload(), {
            source_database: $sourceDatabase.val(),
            target_database: $targetDatabase.val(),
            company_name: $('#company_name').val().trim(),
        });

        $conversionOptions.each(function () {
            payload[this.id] = this.checked;
        });

        return payload;
    }

    function errorMessage(xhr, fallback) {
        return xhr.responseJSON?.message ?? fallback;
    }

    function fillDatabaseSelect($select, databases) {
        const current = $select.val();
        $select.empty().append($('<option>', { value: '', text: 'Select database' }));

        $.each(databases, function (_, db) {
            const $option = $('<option>', { value: db, text: db });
            if (db === current) {
                $option.prop('selected', true);
            }
            $select.append($option);
        });
    }

    function canContinue() {
        const source = $sourceDatabase.val();
        const target = $targetDatabase.val();
        return Boolean(source && target && source !== target);
    }

    function updateContinueButton() {
        if (currentStep !== 2) {
            return;
        }

        const ready = canContinue();
        $continueBtn.prop('disabled', !ready);

        if (ready) {
            $('#pair-source').text($sourceDatabase.val());
            $('#pair-target').text($targetDatabase.val());
            $databasePairHint.removeClass('hidden');
        } else {
            $databasePairHint.addClass('hidden');
        }
    }

    function testConnection() {
        if (currentStep !== 1) {
            return;
        }

        $.post(routes.test, connectionPayload())
            .done(function (res) {
                if (!res.ok) {
                    Swal.fire('Failed', res.message, 'error');
                    return;
                }

                fillDatabaseSelect($sourceDatabase, res.databases);
                fillDatabaseSelect($targetDatabase, res.databases);

                // Swal.fire({
                //     title: 'Connected',
                //     text: `${res.message} ${res.databases.length} database(s) ready.`,
                //     icon: 'success',
                // }).then(function () {
                //     goToStep(2);
                //     updateContinueButton();
                // });

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    showConfirmButton: false,
                    timer: 1000,
                    title: 'Connected',
                    text: `${res.message} ${res.databases.length} database(s) ready.`,
                }).then(function () {
                    goToStep(2);
                    updateContinueButton();
                });
            })
            .fail(function (xhr) {
                Swal.fire('Failed', errorMessage(xhr, 'Connection failed.'), 'error');
            });
    }

    function startConversion() {
        if (!canContinue()) {
            Swal.fire('Missing', 'Select different source and target databases.', 'warning');
            return;
        }

        $('#conversion-source').text($sourceDatabase.val());
        $('#conversion-target').text($targetDatabase.val());
        resetConversionOptions();
        $('#company_name').val('');
        goToStep(3);
    }

    function backToStep1() {
        if (currentStep !== 2) {
            return;
        }
        goToStep(1);
    }

    function backToStep2() {
        if (currentStep !== 3) {
            return;
        }
        goToStep(2);
        updateContinueButton();
    }

    function runConversion() {
        if (currentStep !== 3) {
            return;
        }
        if (!canContinue()) {
            Swal.fire('Missing', 'Select source and target databases.', 'warning');
            return;
        }
        if (!hasConversionSelected()) {
            Swal.fire('Missing', 'Select at least one conversion.', 'warning');
            return;
        }
        if ($('#company_file').is(':checked') && !$('#company_name').val().trim()) {
            Swal.fire('Missing', 'Enter Company Name for Company file conversion.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Convert data?',
            text: 'Run the selected conversion services.',
            icon: 'question',
            showCancelButton: true,
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            $.post(routes.run, fullPayload())
                .done(function (res) {
                    Swal.fire('Done', res.message, 'success');
                })
                .fail(function (xhr) {
                    Swal.fire('Error', errorMessage(xhr, 'Conversion failed.'), 'error');
                });
        });
    }

    $('#load-default-connection').on('click', loadDefaultConnection);
    $('#test-connection').on('click', testConnection);
    $sourceDatabase.add($targetDatabase).on('change', updateContinueButton);
    $continueBtn.on('click', startConversion);
    $('#back-to-step-1').on('click', backToStep1);
    $('#back-to-step-2').on('click', backToStep2);
    $('#convert-btn').on('click', runConversion);

    goToStep(1);
}
