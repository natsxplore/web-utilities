import $ from 'jquery';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
import { initTransferWizard } from './transfer-wizard';

window.$ = window.jQuery = $;
window.Swal = Swal;

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
if (csrfToken) {
    $.ajaxSetup({
        headers: { 'X-CSRF-TOKEN': csrfToken },
    });
}

$(initTransferWizard);
