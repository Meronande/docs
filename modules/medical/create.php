<?php
/**
 * /medical/create — full-page consultation form (same engine as the modal).
 */
declare(strict_types=1);
define('APP_BOOT', true);
require_once dirname(__DIR__, 2) . '/config/auth.php';
require_once dirname(__DIR__, 2) . '/includes/ui.php';
require_once dirname(__DIR__, 2) . '/includes/forms/medical_form.php';
require_permission('medical.create');

$patientId = (int) get('patient_id', '0');
$appointmentId = (int) get('appointment_id', '0');
$patient = $patientId ? db_fetch_one('SELECT id, full_name, patient_code FROM patients WHERE id = ?', [$patientId], 'i') : null;
$appt = $appointmentId ? db_fetch_one('SELECT a.*, p.full_name, p.patient_code FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [$appointmentId], 'i') : null;
if (!$patient && $appt) $patient = ['id' => (int) $appt['patient_id'], 'full_name' => $appt['full_name'], 'patient_code' => $appt['patient_code']];

ui_page_open(['title' => 'New Consultation', 'icon' => 'fa-file-medical', 'breadcrumb' => ['Medical Records' => '/medical', 'New' => null]]);

echo '<div class="card"><div class="card-body">';
echo medical_form_render([], $patient, $appt);
echo '<div class="d-flex justify-content-end gap-2 mt-3"><button class="btn btn-brand" id="fullSave"><i class="fa-solid fa-floppy-disk me-1"></i>Save Consultation</button></div>';
echo '</div></div>';
echo medical_form_script();
?>
<script>
// Submit the full-page form through the same AJAX endpoint
document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('fullSave')?.addEventListener('click', async function () {
    const form = document.getElementById('crudForm');
    const fd = new FormData(form);
    fd.append('csrf_token', App.csrf);
    if (fd.get('id') === '') fd.delete('id');
    this.disabled = true;
    try {
      const res = await fetch(form.dataset.url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': App.csrf }, body: fd });
      const data = await res.json();
      if (data.ok) {
        App.toast(data.message || 'Saved.', 'success');
        setTimeout(() => location.href = '/medical', 600);
      } else {
        App.toast(data.message || 'Failed.', 'danger');
        this.disabled = false;
      }
    } catch (e) {
      App.toast('Network error.', 'danger');
      this.disabled = false;
    }
  });
});
</script>
<?php
ui_page_close();
