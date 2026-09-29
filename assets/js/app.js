/* =====================================================================
   Clinic Management System — app.js
   Notifications, CRUD modals, cascade dropdowns, confirms, live search.
   ===================================================================== */
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  // ------------------------------------------------------------------
  // Helpers exposed to module pages
  // ------------------------------------------------------------------
  window.App = {
    csrf: CSRF,
    async getJSON(url) {
      const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return res.json();
    },
    async postJSON(url, data) {
      const body = new URLSearchParams();
      body.append('csrf_token', CSRF);
      Object.entries(data || {}).forEach(([k, v]) => body.append(k, v ?? ''));
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
        body
      });
      return res.json();
    },
    escapeHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },
    toast(message, type = 'success') {
      const wrap = $('#toastWrap') || (() => {
        const d = document.createElement('div');
        d.id = 'toastWrap';
        d.className = 'toast-container position-fixed top-0 end-0 p-3';
        d.style.zIndex = '2000';
        document.body.appendChild(d);
        return d;
      })();
      const el = document.createElement('div');
      const icon = { success: 'fa-circle-check text-success', danger: 'fa-circle-exclamation text-danger', warning: 'fa-triangle-exclamation text-warning', info: 'fa-circle-info text-primary' }[type] || 'fa-bell text-primary';
      el.className = 'toast align-items-center border-0';
      el.setAttribute('role', 'alert');
      el.innerHTML = `<div class="d-flex"><div class="toast-body d-flex gap-2 align-items-start">
          <i class="fa-solid ${icon} mt-1"></i><div>${message}</div></div>
          <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
      wrap.appendChild(el);
      const t = new bootstrap.Toast(el, { delay: 4500 });
      t.show();
      el.addEventListener('hidden.bs.toast', () => el.remove());
    }
  };

  // ------------------------------------------------------------------
  // Sidebar toggle
  // ------------------------------------------------------------------
  const sidebar = $('#appSidebar');
  const backdrop = $('#sidebarBackdrop');
  $('#sidebarToggle')?.addEventListener('click', () => {
    if (window.innerWidth < 992) {
      sidebar.classList.toggle('open');
      backdrop.classList.toggle('show', sidebar.classList.contains('open'));
    } else {
      sidebar.classList.toggle('collapsed');
      $('.app-main')?.classList.toggle('expanded');
    }
  });
  backdrop?.addEventListener('click', () => {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
  });

  // ------------------------------------------------------------------
  // Notifications
  // ------------------------------------------------------------------
  const notifList = $('#notifList');
  const notifCount = $('#notifCount');
  const notifBell = $('#notifBell');

  const NOTIF_ICONS = {
    success: ['fa-circle-check', 'text-success'], warning: ['fa-triangle-exclamation', 'text-warning'],
    danger: ['fa-circle-exclamation', 'text-danger'], appointment: ['fa-calendar-check', 'text-primary'],
    laboratory: ['fa-flask-vial', 'text-info'], pharmacy: ['fa-pills', 'text-success'],
    finance: ['fa-money-bill-wave', 'text-warning'], system: ['fa-gear', 'text-secondary'],
    info: ['fa-circle-info', 'text-primary']
  };

  async function loadNotifications() {
    if (!notifList) return;
    try {
      const data = await App.getJSON('/ajax/notifications');
      notifCount.textContent = data.unread > 99 ? '99+' : data.unread;
      notifCount.classList.toggle('d-none', data.unread === 0);
      if (!data.items.length) {
        notifList.innerHTML = '<div class="text-center text-muted py-4 small">No notifications yet</div>';
        return;
      }
      notifList.innerHTML = data.items.map(n => {
        const [icon, color] = NOTIF_ICONS[n.notification_type] || NOTIF_ICONS.info;
        return `<div class="notif-item ${n.unread ? 'unread' : ''}" data-id="${n.id}" ${n.url ? `data-url="${App.escapeHtml(n.url)}"` : ''}>
          <i class="fa-solid ${icon} ${color} mt-1"></i>
          <div class="flex-grow-1">
            <div class="n-title">${App.escapeHtml(n.title)}</div>
            <div class="n-msg">${App.escapeHtml(n.message || '')}</div>
            <div class="n-time">${App.escapeHtml(n.ago)}</div>
          </div>
        </div>`;
      }).join('');
    } catch (e) { /* silent */ }
  }

  notifList?.addEventListener('click', async (ev) => {
    const item = ev.target.closest('.notif-item');
    if (!item) return;
    const id = item.dataset.id;
    if (id) await App.postJSON('/ajax/notifications', { action: 'read', id });
    if (item.dataset.url) {
      window.location.href = item.dataset.url;
    } else {
      item.classList.remove('unread');
      loadNotifications();
    }
  });
  $('#notifMarkAll')?.addEventListener('click', async () => {
    await App.postJSON('/ajax/notifications', { action: 'read_all' });
    loadNotifications();
  });
  $('#notifRefresh')?.addEventListener('click', (ev) => { ev.preventDefault(); loadNotifications(); });
  loadNotifications();
  setInterval(loadNotifications, 30000); // live badge updates

  // ------------------------------------------------------------------
  // AJAX CRUD modal engine
  // Pages declare: data-crud-url="/ajax/branches" data-crud-title="Branch"
  // Buttons:  data-action="create|edit" data-id="..."
  // ------------------------------------------------------------------
  let crudModal = null;

  function ensureModal() {
    if (crudModal) return crudModal;
    const wrap = document.createElement('div');
    wrap.innerHTML = `<div class="modal fade" id="appCrudModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="crudTitle">Edit</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="crudBody"></div>
            <div class="modal-footer">
              <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-brand" id="crudSave"><i class="fa-solid fa-floppy-disk me-1"></i>Save</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.appendChild(wrap);
    crudModal = new bootstrap.Modal($('#appCrudModal'));
    return crudModal;
  }

  async function openCrud(url, title, id) {
    ensureModal();
    $('#crudTitle').textContent = title;
    $('#crudBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-secondary"></div></div>';
    crudModal.show();
    try {
      const data = await App.getJSON(id ? `${url}?id=${id}` : url);
      $('#crudBody').innerHTML = data.html;
      $('#crudBody').querySelector('input:not([type=hidden]), select, textarea')?.focus();
    } catch (e) {
      $('#crudBody').innerHTML = '<div class="alert alert-danger">Could not load the form. Please try again.</div>';
    }
  }

  $('#crudSave') && document.addEventListener('click', (ev) => {
    if (ev.target && ev.target.id === 'crudSave') saveCrud();
  });
  document.addEventListener('submit', (ev) => {
    const form = ev.target;
    if (form.id === 'crudForm') { ev.preventDefault(); saveCrud(); }
  });

  async function saveCrud() {
    const form = $('#crudForm');
    if (!form) return;
    const saveBtn = $('#crudSave');
    const url = form.dataset.url;
    const fd = new FormData(form);
    fd.append('csrf_token', CSRF);
    if (fd.get('id') === '') fd.delete('id');
    saveBtn.disabled = true;
    try {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
        body: fd
      });
      const data = await res.json();
      if (data.ok) {
        crudModal.hide();
        App.toast(data.message || 'Saved successfully.', 'success');
        if (data.reload !== false) setTimeout(() => window.location.reload(), 500);
      } else {
        App.toast(data.message || 'Something went wrong.', 'danger');
      }
    } catch (e) {
      App.toast('Network error. Please try again.', 'danger');
    } finally {
      saveBtn.disabled = false;
    }
  }

  // Delegated handlers for create/edit/delete/toggle buttons
  document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-action]');
    if (!btn) return;
    const container = btn.closest('[data-crud-url]');
    const action = btn.dataset.action;

    if (action === 'create' || action === 'edit') {
      ev.preventDefault();
      const url = btn.dataset.url || container?.dataset.crudUrl;
      if (!url) return;
      const title = btn.dataset.title || container?.dataset.crudTitle || 'Record';
      openCrud(url, title, action === 'edit' ? btn.dataset.id : null);
    }

    if (action === 'delete') {
      ev.preventDefault();
      const url = btn.dataset.url || container?.dataset.crudUrl;
      if (!url) return;
      const name = btn.dataset.name || 'this record';
      SwalSafe(`Delete ${btn.dataset.title || container?.dataset.crudTitle || 'record'}?`,
        `You are about to delete <strong>${App.escapeHtml(name)}</strong>.<br>If it is already used elsewhere, it cannot be removed.`,
        async () => {
          const data = await App.postJSON(url, { action: 'delete', id: btn.dataset.id });
          if (data.ok) {
            App.toast(data.message || 'Deleted.', 'success');
            if (btn.closest('tr')) btn.closest('tr').remove();
            else setTimeout(() => window.location.reload(), 400);
          } else {
            App.toast(data.message || 'Could not delete.', 'danger');
          }
        });
    }

    if (action === 'toggle') {
      ev.preventDefault();
      const url = btn.dataset.url || container?.dataset.crudUrl;
      if (!url) return;
      App.postJSON(url, { action: 'toggle', id: btn.dataset.id }).then(data => {
        if (data.ok) { window.location.reload(); }
        else App.toast(data.message || 'Could not change status.', 'danger');
      });
    }
  });

  function SwalSafe(title, html, onConfirm, confirmText = 'Yes, continue') {
    if (window.Swal) {
      Swal.fire({
        title, html, icon: 'warning', showCancelButton: true,
        confirmButtonText: confirmText, cancelButtonText: 'Cancel',
        confirmButtonColor: '#0d8a80', cancelButtonColor: '#6c757d'
      }).then(r => { if (r.isConfirmed) onConfirm(); });
    } else if (confirm(title)) {
      onConfirm();
    }
  }
  window.AppConfirm = SwalSafe;

  // ------------------------------------------------------------------
  // Cascade dropdowns:
  // <select data-cascade="departments" data-target="#deptId">
  // Target select gets options from /ajax/lookup?type=departments&parent=<val>
  // ------------------------------------------------------------------
  $$('select[data-cascade]').forEach(sel => {
    const target = $(sel.dataset.target);
    if (!target) return;
    const type = sel.dataset.cascade;
    const loader = async () => {
      const parent = sel.value;
      const keep = target.dataset.keepValue || target.value;
      const data = await App.getJSON(`/ajax/lookup?type=${type}&parent=${encodeURIComponent(parent)}`);
      const placeholder = target.dataset.placeholder || '— Select —';
      target.innerHTML = `<option value="">${App.escapeHtml(placeholder)}</option>` +
        data.items.map(it => `<option value="${it.id}" ${String(it.id) === String(keep) ? 'selected' : ''}>${App.escapeHtml(it.name)}</option>`).join('');
      target.dispatchEvent(new Event('change', { bubbles: true }));
    };
    sel.addEventListener('change', loader);
    if (sel.value) loader();
  });

  // ------------------------------------------------------------------
  // Live table search (client-side over current page rows)
  // ------------------------------------------------------------------
  $$('input[data-table-search]').forEach(input => {
    const table = $(input.dataset.tableSearch);
    if (!table) return;
    input.addEventListener('input', () => {
      const q = input.value.toLowerCase();
      $$('tbody tr', table).forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  });

  // Auto-dismiss success alerts after 5s
  $$('div.alert-success').forEach(a => setTimeout(() => bootstrap.Alert.getOrCreateInstance(a)?.close(), 5000));

  // ------------------------------------------------------------------
  // Queue board auto refresh
  // ------------------------------------------------------------------
  if (document.body.dataset.queuePage) {
    setInterval(() => { location.reload(); }, 20000);
  }
})();
