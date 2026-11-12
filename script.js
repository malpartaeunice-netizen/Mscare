/* =========================================================
   MSCare — frontend script (used by every page).
   FRONTEND ONLY: there is no PHP / database. Accounts, bookings and
   login sessions are kept in the browser (localStorage), shared by the
   patient site and the admin site when both are opened from the same
   address (e.g. http://localhost/). The pages are still named .php.
   ========================================================= */

/* ---------- sample data ---------- */
const CHECK_SVG = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

const DENTIST = 'Dr. Marilou S. Cariño';

/* two clinics, one dentist. days = JS getDay(): 0 Sun ... 6 Sat */
const CLINICS = {
  1: { label: 'Clinic 1 · Makati', admin: 'Clinic 1 Admin', initials: 'A1', place: 'Makati branch',
       days: [1, 3, 5, 6], daysText: 'Mon, Wed, Fri & Sat' },
  2: { label: 'Clinic 2 · Manila', admin: 'Clinic 2 Admin', initials: 'A2', place: 'Manila branch',
       days: [2, 4], daysText: 'Tue & Thu' },
};

const esc = v => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const STATUS_BADGE = { Pending: 'b-pending', Confirmed: 'b-confirmed', Completed: 'b-completed', Cancelled: 'b-cancelled' };
const TIME_SLOTS = ['09:00 AM', '10:00 AM', '11:00 AM', '01:00 PM', '02:00 PM', '03:00 PM', '04:00 PM'];
const SERVICES = ['General Dentistry', 'Cleaning', 'Orthodontics', 'Esthetic Dentistry', 'Prosthodontics', 'Root Canal Treatment', 'Oral Surgery', 'Braces'];

/* ---------- browser storage (the "database") ---------- */
const _mem = {};
function kvGet(k) { try { const v = localStorage.getItem(k); return v === null ? (_mem[k] ?? null) : v; } catch (e) { return _mem[k] ?? null; } }
function kvSet(k, v) { _mem[k] = v; try { localStorage.setItem(k, v); } catch (e) {} }
function kvDel(k) { delete _mem[k]; try { localStorage.removeItem(k); } catch (e) {} }

/* tiny password scramble so passwords are not stored as plain text (demo only, not real security) */
function hashPw(str) {
  let h1 = 0xdeadbeef, h2 = 0x41c6ce57;
  for (let i = 0; i < str.length; i++) { const c = str.charCodeAt(i); h1 = Math.imul(h1 ^ c, 2654435761); h2 = Math.imul(h2 ^ c, 1597334677); }
  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
  return (4294967296 * (2097151 & h2) + (h1 >>> 0)).toString(36);
}
function seedDB() {
  return {
    admins: [
      { id: 1, username: 'admin1', pass: hashPw('admin123'), clinic: 1, display_name: CLINICS[1].admin },
      { id: 2, username: 'admin2', pass: hashPw('admin123'), clinic: 2, display_name: CLINICS[2].admin },
    ],
    patients: [
      { id: 1, is_demo: 1, last_name: 'Lopez', first_name: 'Maria', middle_name: '', full_name: 'Maria Lopez', age: 34, gender: 'Female',
        email: 'maria.lopez@mail.com', phone: '0917 220 3391', pass: hashPw('maria1234') },
    ],
    appts: [], nextPatient: 2, nextAppt: 1,
  };
}
const DB_KEY = 'mscare_db_v1';
let DB = null;
try { DB = JSON.parse(kvGet(DB_KEY)); } catch (e) { DB = null; }
if (!DB || !DB.patients) { DB = seedDB(); kvSet(DB_KEY, JSON.stringify(DB)); }
function saveDB() { kvSet(DB_KEY, JSON.stringify(DB)); }

/* which site is this page on? */
const ROLE = (() => {
  const d = document.body.dataset;
  return d.guard || d.loginRole || d.otpRole || 'patient';
})();
const SESSION_KEY = { patient: 'mscare_session_patient', admin: 'mscare_session_admin' };
const OTP_KEY = 'mscare_otp';
const FLASH_KEY = 'mscare_flash';
function flash(msg) { kvSet(FLASH_KEY, msg); }
function takeFlash() { const m = kvGet(FLASH_KEY) || ''; kvDel(FLASH_KEY); return m; }

function currentPatient() { const id = parseInt(kvGet(SESSION_KEY.patient), 10); return DB.patients.find(p => p.id === id) || null; }
function currentAdmin() { const id = parseInt(kvGet(SESSION_KEY.admin), 10); return DB.admins.find(a => a.id === id) || null; }
function patientKey(p) { return p.is_demo ? 'p1' : 'db' + p.id; }
function patientSessionJson(p) {
  const demo = !!p.is_demo;
  return { role: 'patient', name: p.full_name, firstName: p.first_name, lastName: p.last_name, middleName: p.middle_name,
           patientId: demo ? 'p1' : 'new', uid: patientKey(p), registered: !demo, email: p.email, phone: p.phone, age: p.age, gender: p.gender };
}
function adminSessionJson(a) { return { role: 'admin', name: a.display_name, clinic: a.clinic, uid: 'admin' + a.id }; }
function apptShape(r) { return { id: r.id, service: r.service, clinic: r.clinic, dentist: r.dentist, date: r.date, time: r.time, status: r.status, patient: r.patient_name }; }
function sortAppts(list) {
  return list.slice().sort((a, b) => (a.date < b.date ? -1 : a.date > b.date ? 1 : 0) || (TIME_SLOTS.indexOf(a.time) - TIME_SLOTS.indexOf(b.time)) || (a.id - b.id));
}
function bookedSlotsList() {
  const today = dayFromToday(0);
  return DB.appts.filter(a => (a.status === 'Pending' || a.status === 'Confirmed') && a.date >= today).map(a => {
    const p = a.patient_id ? DB.patients.find(x => x.id === a.patient_id) : null;
    return { date: a.date, time: a.time, dentist: a.dentist, uid: p ? patientKey(p) : '' };
  });
}

/* booking rules (same as the old server checks) */
function slotTaken(dentist, date, time) { return DB.appts.some(a => a.date === date && a.time === time && a.dentist === dentist && (a.status === 'Pending' || a.status === 'Confirmed')); }
function patientBusy(pid, date, time) { return !!pid && DB.appts.some(a => a.patient_id === pid && a.date === date && a.time === time && (a.status === 'Pending' || a.status === 'Confirmed')); }
function checkBooking(pid, label, clinic, service, dentist, date, time) {
  if (!CLINICS[clinic]) return 'Please choose a clinic.';
  if (!SERVICES.includes(service)) return 'Please choose a service.';
  if (dentist !== DENTIST) return 'Please choose a dentist.';
  if (!TIME_SLOTS.includes(time)) return 'Please choose a valid time.';
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date < dayFromToday(0)) return 'Please choose a date that is today or later.';
  if (!clinicOpen(clinic, date)) return CLINICS[clinic].label + ' is only open ' + CLINICS[clinic].daysText + '.';
  if (slotTaken(dentist, date, time)) return dentist + ' is already booked at that time.';
  if (patientBusy(pid, date, time)) return (label === 'You' ? 'You already have' : label + ' already has') + ' an appointment at that time.';
  return null;
}
function addAppt(o) {
  const row = Object.assign({ id: DB.nextAppt++, notes: null, created_at: Date.now() }, o);
  DB.appts.push(row); saveDB(); return row;
}

/* what the page needs (the old server printed this as window.MSCARE) */
const MS = (() => {
  const out = { session: null, flash: takeFlash(), appts: [], booked: [] };
  if (ROLE === 'admin') {
    const a = currentAdmin();
    if (a) { out.session = adminSessionJson(a); out.appts = sortAppts(DB.appts.filter(x => x.clinic === a.clinic)).map(apptShape); }
  } else {
    const p = currentPatient();
    if (p) { out.session = patientSessionJson(p); out.appts = sortAppts(DB.appts.filter(x => x.patient_id === p.id)).map(apptShape); out.booked = bookedSlotsList(); }
  }
  return out;
})();

/* change an appointment: patient cancel / admin confirm, complete, cancel, create. Same answers the old server gave. */
const ADMIN_NAMES = { p2: 'John Reyes', p3: 'Ana Santos', p4: 'Carlo Dela Cruz', p5: 'Ana Bienvenu', p6: 'Liam Cortez' };
function api(data) {
  const done = r => Promise.resolve(r);
  const id = parseInt(data.id, 10), action = data.action;
  if (ROLE === 'patient') {
    const p = currentPatient();
    if (!p) return done({ ok: false, error: 'Please log in again.' });
    if (action !== 'cancel') return done({ ok: false, error: 'Unknown action.' });
    const a = DB.appts.find(x => x.id === id && x.patient_id === p.id && (x.status === 'Pending' || x.status === 'Confirmed'));
    if (!a) return done({ ok: false, error: 'That appointment can no longer be cancelled.' });
    a.status = 'Cancelled'; saveDB(); return done({ ok: true });
  }
  const adm = currentAdmin();
  if (!adm) return done({ ok: false, error: 'Please log in again.' });
  if (action === 'create') {
    const key = String(data.patient || ''); let pid = null, name = '', m;
    if ((m = key.match(/^db(\d+)$/))) { const r = DB.patients.find(x => x.id === parseInt(m[1], 10)); if (r) { pid = r.id; name = r.full_name; } }
    else if (key === 'p1') { const r = DB.patients.find(x => x.is_demo); if (r) { pid = r.id; name = r.full_name; } }
    else name = ADMIN_NAMES[key] || '';
    if (!name) return done({ ok: false, error: 'Please choose a patient.' });
    const err = checkBooking(pid, name, adm.clinic, data.service, data.dentist, data.date, data.time);
    if (err) return done({ ok: false, error: err });
    const row = addAppt({ patient_id: pid, patient_name: name, service: data.service, clinic: adm.clinic, dentist: data.dentist, date: data.date, time: data.time, status: 'Confirmed', created_by: 'admin' });
    return done({ ok: true, appt: apptShape(row) });
  }
  const map = { confirm: ['Confirmed', ['Pending']], complete: ['Completed', ['Confirmed']], cancel: ['Cancelled', ['Pending', 'Confirmed']] };
  if (!map[action]) return done({ ok: false, error: 'Unknown action.' });
  const a = DB.appts.find(x => x.id === id && x.clinic === adm.clinic && map[action][1].includes(x.status));
  if (!a) return done({ ok: false, error: 'That appointment could not be changed (it may already have been updated).' });
  a.status = map[action][0]; saveDB(); return done({ ok: true });
}

/* Tooth status colours (used for the circles, status boxes and legend) */
const CAT = {
  healthy: { label: 'Healthy' },
  cavity:  { label: 'Cavity' },
  filling: { label: 'Filling' },
  crown:   { label: 'Crown / prosthetic' },
  root:    { label: 'Root canal / fragment' },
  missing: { label: 'Missing / extraction' },
};
/* Status codes the dentist can pick for a tooth */
const CODES = {
  'D':   { name: 'Decayed (caries)',               cat: 'cavity' },
  'M':   { name: 'Missing due to caries',          cat: 'missing' },
  'F':   { name: 'Filled',                         cat: 'filling' },
  'I':   { name: 'Caries indicated for extraction', cat: 'cavity' },
  'RF':  { name: 'Root fragment',                  cat: 'root' },
  'MO':  { name: 'Missing due to other causes',    cat: 'missing' },
  'Im':  { name: 'Impacted tooth',                 cat: 'healthy' },
  'RC':  { name: 'Root canal treatment',           cat: 'root' },
  'J':   { name: 'Jacket crown',                   cat: 'crown' },
  'A':   { name: 'Amalgam filling',                cat: 'filling' },
  'A-B': { name: 'Abutment',                       cat: 'crown' },
  'P':   { name: 'Pontic',                         cat: 'crown' },
  'In':  { name: 'Inlay',                          cat: 'filling' },
  'FX':  { name: 'Fixed cure composite',           cat: 'filling' },
  'Rm':  { name: 'Removable denture',              cat: 'crown' },
  'X':   { name: 'Extraction due to caries',       cat: 'missing' },
  'XO':  { name: 'Extraction due to other causes', cat: 'missing' },
  '✓':   { name: 'Present tooth',                  cat: 'healthy' },
  'Cm':  { name: 'Congenitally missing',           cat: 'missing' },
  'Sp':  { name: 'Supernumerary',                  cat: 'healthy' },
};
const CODE_GROUPS = [
  { title: 'Condition', codes: ['D', 'M', 'F', 'I', 'RF', 'MO', 'Im', 'RC'] },
  { title: 'Restoration & prosthetics', codes: ['J', 'A', 'A-B', 'P', 'In', 'FX', 'Rm'] },
  { title: 'Surgery', codes: ['X', 'XO', '✓', 'Cm', 'Sp'] },
];

/* chart data per patient. Teeth use FDI numbers (18-11 / 21-28 upper, 48-41 / 31-38 lower, 55-51 / 61-65 and 85-81 / 71-75 temporary) */
const CHART_DATA = {
  p1: { name: 'Maria Lopez', age: 34, gender: 'Female', teeth: { 16: 'D', 26: 'F', 36: 'J', 72: 'RC' },
        notes: { 26: { procedure: 'Filling', date: 'Jul 31, 2026', dentist: DENTIST, note: 'Composite filling, upper left molar.' } } },
  p2: { name: 'John Reyes', age: 28, gender: 'Male', teeth: { 21: 'RC', 46: 'X' },
        notes: { 21: { procedure: 'Root Canal Treatment', date: 'Oct 6, 2026', dentist: DENTIST, note: 'Stage 2 of 3, follow-up needed.' } } },
  p3: { name: 'Ana Santos', age: 41, gender: 'Female', teeth: {}, notes: {} },
  p4: { name: 'Carlo Dela Cruz', age: 22, gender: 'Male', teeth: { 17: 'D', 26: 'D' }, notes: {} },
  p5: { name: 'Ana Bienvenu', age: 37, gender: 'Female', teeth: { 11: 'J' },
        notes: { 11: { procedure: 'Crown Placement', date: 'Aug 10, 2026', dentist: DENTIST, note: 'Porcelain crown, front tooth.' } } },
  p6: { name: 'Liam Cortez', age: 19, gender: 'Male', teeth: {}, notes: {} },
  new: { name: 'New patient', age: '', gender: '', teeth: {}, notes: {} },
};
function toothInfo(n) {
  const q = Math.floor(n / 10);
  const side = { 1: 'upper right', 2: 'upper left', 3: 'lower left', 4: 'lower right', 5: 'upper right', 6: 'upper left', 7: 'lower left', 8: 'lower right' }[q];
  return (q <= 4 ? 'Permanent ' : 'Temporary ') + side;
}

/* existing bookings (dayOffset from today) used for conflict detection */
const BOOKED = [
  { patient: 'p1', offset: 0, time: '08:00 AM', dentist: DENTIST },
  { patient: 'p2', offset: 0, time: '09:30 AM', dentist: DENTIST },
  { patient: 'p3', offset: 0, time: '11:00 AM', dentist: DENTIST },
  { patient: 'p4', offset: 1, time: '01:00 PM', dentist: DENTIST },
  { patient: 'p6', offset: 4, time: '10:00 AM', dentist: DENTIST },
];

/* ---------- helpers ---------- */
const $  = (s, r) => (r || document).querySelector(s);
const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

function iso(d) {
  const z = new Date(d.getTime() - d.getTimezoneOffset() * 60000);
  return z.toISOString().slice(0, 10);
}
function dayFromToday(n) { const d = new Date(); d.setDate(d.getDate() + n); return iso(d); }
function clinicOpen(c, dateStr) { return CLINICS[c].days.includes(new Date(dateStr + 'T00:00:00').getDay()); }
function nextOpenFrom(c, dateStr) {
  const d = new Date(dateStr + 'T00:00:00');
  for (let i = 0; i < 14; i++) { if (CLINICS[c].days.includes(d.getDay())) return iso(d); d.setDate(d.getDate() + 1); }
  return dateStr;
}
function initials(name) { return name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase(); }
/* first name / initials for a patient session (uses the separate name parts when we have them) */
function firstNameOf(s) { return s.firstName || s.name.split(' ')[0]; }
function initialsOf(s) { return s.firstName && s.lastName ? (s.firstName[0] + s.lastName[0]).toUpperCase() : initials(s.name); }
/* anything marked data-uppercase turns into CAPITALS as the patient types */
function initUppercase() {
  $$('[data-uppercase]').forEach(el => {
    const up = () => {
      const a = el.selectionStart, b = el.selectionEnd, u = el.value.toUpperCase();
      if (u === el.value) return;
      el.value = u;
      try { el.setSelectionRange(a, b); } catch (e) {}
    };
    el.addEventListener('input', up);
    up();
  });
}
function fmtDate(s) { return new Date(s + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }

function showToast(msg) {
  const old = $('.toast'); if (old) old.remove();
  const t = document.createElement('div');
  t.className = 'toast';
  t.innerHTML = CHECK_SVG + '<span>' + msg + '</span>';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 2800);
}

/* ---------- session ---------- */
function getSession() { return MS.session || null; }
function logout() {
  kvDel(SESSION_KEY[ROLE]); kvDel(OTP_KEY);
  location.href = ROLE === 'admin' ? 'login.php' : 'index.php';
}

function guardPage() {
  const need = document.body.dataset.guard;      // 'patient' | 'admin'
  if (!need) return;
  const s = getSession();
  if (!s || s.role !== need) { location.replace('login.php'); return; }   /* not logged in */
  if (need === 'patient') {
    $$('[data-user-name]').forEach(el => el.textContent = s.name);
    $$('[data-user-first]').forEach(el => el.textContent = firstNameOf(s));
    $$('[data-user-initials]').forEach(el => el.textContent = initialsOf(s));
    $$('[data-user-input]').forEach(el => el.value = s.name);
    /* a newly registered patient sees their own details and empty records, not the sample patient's */
    $$('[data-user-email]').forEach(el => el.value = s.email || '');
    $$('[data-user-phone]').forEach(el => el.value = s.phone || '');
    $$('[data-user-age]').forEach(el => el.value = s.age || '');
    $$('[data-user-gender]').forEach(el => { el.value = s.gender || el.value; });
    if (s.registered) {
      $$('[data-demo-only]').forEach(el => el.remove());
      $$('[data-user-lastvisit]').forEach(el => el.value = 'No visits yet');
    } else {
      $$('[data-new-only]').forEach(el => el.remove());
    }
  } else {
    scopeClinic(s.clinic || 1);
  }
}

/* an admin only sees their own clinic: rows/blocks tagged for the other clinic are removed */
function scopeClinic(c) {
  const mine = String(c), info = CLINICS[c];
  $$('[data-clinic]').forEach(el => { if (!el.dataset.clinic.split(' ').includes(mine)) el.remove(); });
  $$('[data-clinic-only]').forEach(el => { if (el.dataset.clinicOnly !== mine) el.remove(); });
  $$('[data-c1]').forEach(el => { el.textContent = el.dataset['c' + mine]; });
  $$('[data-clinic-name]').forEach(el => el.textContent = info.label);
  $$('[data-clinic-days]').forEach(el => el.textContent = info.daysText);
  $$('[data-admin-name]').forEach(el => el.textContent = info.admin);
  $$('[data-admin-place]').forEach(el => el.textContent = info.place);
  $$('[data-admin-initials]').forEach(el => el.textContent = info.initials);
  $$('[data-row-count]').forEach(el => { el.textContent = $$('#' + el.dataset.rowCount + ' tbody tr').length; });
}

/* ---------- appointment row buttons ---------- */
function setRowStatus(row, status) {
  row.dataset.status = status;
  const badge = $('.badge', row);
  if (badge) { badge.className = 'badge ' + STATUS_BADGE[status]; badge.textContent = status; }
}
function wireRowButtons(root) {
  const run = (btn, action, status, done, after) => {
    const row = btn.closest('tr');
    const apply = () => { setRowStatus(row, status); after(row, btn); showToast(done); };
    if (!row.dataset.id) return apply();               // sample rows: just change the screen
    btn.disabled = true;
    api({ id: row.dataset.id, action: action }).then(r => {
      if (r.ok) apply(); else { btn.disabled = false; showToast(r.error || 'Something went wrong.'); }
    });
  };
  const clearActions = row => $$('[data-row-action]', row).forEach(a => a.remove());
  $$('[data-cancel-row]', root).forEach(btn => btn.addEventListener('click', () => run(btn, 'cancel', 'Cancelled', 'Appointment cancelled.', clearActions)));
  $$('[data-confirm-row]', root).forEach(btn => btn.addEventListener('click', () => run(btn, 'confirm', 'Confirmed', 'Appointment confirmed.', (row, b) => {
    b.remove();
    const act = $('.action-row', row);
    if (act && row.dataset.id && !$('[data-complete-row]', row)) {
      const c = document.createElement('button'); c.type = 'button'; c.className = 'icon-btn'; c.title = 'Mark completed';
      c.setAttribute('data-complete-row', ''); c.setAttribute('data-row-action', ''); c.innerHTML = CHECK_SVG;
      act.insertBefore(c, act.firstChild); wireRowButtons(act);
    }
  })));
  $$('[data-complete-row]', root).forEach(btn => btn.addEventListener('click', () => run(btn, 'complete', 'Completed', 'Appointment marked as completed.', clearActions)));
}

/* ---------- shared UI behavior ---------- */
function initCommon() {
  if (MS.flash) setTimeout(() => showToast(MS.flash), 250);

  $$('[data-logout]').forEach(el => el.addEventListener('click', e => { e.preventDefault(); logout(); }));

  /* notification bell: handled by includes/bell.php (inline script) */

  /* modals */
  $$('[data-open-modal]').forEach(b => b.addEventListener('click', () => $('#' + b.dataset.openModal).classList.add('open')));
  $$('[data-close-modal]').forEach(b => b.addEventListener('click', () => b.closest('.modal-backdrop').classList.remove('open')));
  $$('.modal-backdrop').forEach(m => m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); }));

  /* status tabs that filter table rows */
  $$('[data-filter-tabs]').forEach(group => {
    const rows = () => $$('#' + group.dataset.filterTabs + ' tbody tr');
    $$('.tab', group).forEach(tab => tab.addEventListener('click', () => {
      $$('.tab', group).forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      const f = tab.dataset.filter;
      let shown = 0;
      rows().forEach(r => { const ok = f === 'all' || r.dataset.status === f; r.classList.toggle('hidden', !ok); if (ok) shown++; });
      const empty = $('#' + group.dataset.filterTabs + 'Empty');
      if (empty) empty.classList.toggle('hidden', shown > 0);
    }));
  });

  /* live search */
  const search = $('#patientSearch');
  if (search) search.addEventListener('input', () => {
    const q = search.value.toLowerCase();
    let shown = 0;
    $$('#patientsTable tbody tr').forEach(r => { const ok = r.textContent.toLowerCase().includes(q); r.classList.toggle('hidden', !ok); if (ok) shown++; });
    const empty = $('#patientsEmpty'); if (empty) empty.classList.toggle('hidden', shown > 0);
  });

  /* appointment buttons (rows that came from the database carry data-id) */
  wireRowButtons(document);
  $$('[data-remind-row]').forEach(btn => btn.addEventListener('click', () => {
    const row = btn.closest('tr');
    const name = row.dataset.patient || 'the patient';
    if (!$('.reminder-sent-tag', row)) {
      const tag = document.createElement('div'); tag.className = 'reminder-sent-tag'; tag.textContent = 'Reminder sent';
      btn.closest('td').appendChild(tag);
    }
    showToast('Reminder sent to ' + name + '.');
  }));

  /* generic demo forms */
  $$('form[data-toast]').forEach(f => f.addEventListener('submit', e => {
    e.preventDefault(); showToast(f.dataset.toast);
    if (f.dataset.reset !== undefined) f.reset();
  }));
}

/* ---------- helpers for sign-in pages ---------- */
function upperName(v) { return String(v || '').replace(/\s+/g, ' ').trim().toUpperCase(); }
function validNamePart(v) { return /^[\p{L}][\p{L} .'\-]*$/u.test(v) && v.length <= 60; }
function maskContact(t) {
  if (!t.includes('@')) return t;
  const i = t.indexOf('@'), u = t.slice(0, i), d = t.slice(i + 1);
  return u.slice(0, 2) + '•'.repeat(Math.max(u.length - 2, 2)) + '@' + d;
}
function showFormError(id, msg) { const e = $('#' + id); if (!e) return; e.textContent = msg; e.classList.toggle('hidden', !msg); }
function newCode() { return String(100000 + Math.floor(Math.random() * 900000)); }
function startOtp(role, id, target, welcome) {
  kvSet(OTP_KEY, JSON.stringify({ role: role, id: id, target: target, code: newCode(), attempts: 3, expires: Date.now() + 600000, welcome: welcome || '' }));
  location.href = 'otp.php';
}
function readOtp() { try { return JSON.parse(kvGet(OTP_KEY)); } catch (e) { return null; } }

/* ---------- log in (patient + admin) ---------- */
function initLogin() {
  const form = $('#loginForm'); if (!form) return;
  const role = document.body.dataset.loginRole || 'patient';
  if (role === 'admin' ? currentAdmin() : currentPatient()) { location.replace(role === 'admin' ? 'index.php' : 'patient-dashboard.php'); return; }
  form.addEventListener('submit', e => {
    e.preventDefault();
    const pass = form.elements.password.value;
    if (role === 'admin') {
      const user = form.elements.username.value.trim();
      const a = DB.admins.find(x => x.username === user);
      if (a && a.pass === hashPw(pass)) return startOtp('admin', a.id, 'the Clinic ' + a.clinic + ' admin phone on file');
      return showFormError('loginError', 'Incorrect admin username or password.');
    }
    const email = form.elements.email.value.trim();
    if (pass === '') return showFormError('loginError', 'Please enter a password.');
    const p = DB.patients.find(x => x.email.toLowerCase() === email.toLowerCase());
    if (!p) return showFormError('loginError', 'No patient account found for that email. Please register first.');
    if (p.pass !== hashPw(pass)) return showFormError('loginError', 'Incorrect password.');
    startOtp('patient', p.id, p.email);
  });
}

/* ---------- register (patient) ---------- */
function initRegister() {
  const form = $('#regForm'); if (!form) return;
  if (currentPatient()) { location.replace('patient-dashboard.php'); return; }
  form.addEventListener('submit', e => {
    e.preventDefault();
    const f = form.elements, v = n => (f[n] ? f[n].value : '').trim();
    const last = upperName(v('last_name')), first = upperName(v('first_name')), middle = upperName(v('middle_name'));
    const age = parseInt(v('age'), 10) || 0, email = v('email'), phone = v('phone');
    const pass = f.password.value, pass2 = f.password2.value;
    const gender = ['Female', 'Male', 'Prefer not to say'].includes(v('gender')) ? v('gender') : 'Prefer not to say';
    f.last_name.value = last; f.first_name.value = first; if (f.middle_name) f.middle_name.value = middle;
    const err = m => showFormError('regError', m);
    if (!last) return err('Please enter your last name.');
    if (!first) return err('Please enter your first name.');
    if (!validNamePart(last) || !validNamePart(first) || (middle && !validNamePart(middle))) return err('Names can only contain letters, spaces, hyphens, apostrophes and periods.');
    if (age < 1 || age > 120) return err('Please enter a valid age.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || email.length > 120) return err('Please enter a valid email address.');
    if (DB.patients.some(x => x.email.toLowerCase() === email.toLowerCase())) return err('An account with that email already exists. Try logging in instead.');
    if (!/^[0-9+\-\s()]{7,20}$/.test(phone)) return err('Please enter your mobile number.');
    if (pass.length < 6) return err('Password must be at least 6 characters.');
    if (pass !== pass2) return err('Passwords do not match.');
    if (!f.consent.checked) return err('Please agree to the records consent to continue.');
    const full = [first, middle, last].filter(Boolean).join(' ');
    const p = { id: DB.nextPatient++, is_demo: 0, last_name: last, first_name: first, middle_name: middle, full_name: full, age: age, gender: gender, email: email, phone: phone, pass: hashPw(pass) };
    DB.patients.push(p); saveDB();
    startOtp('patient', p.id, email, 'Welcome to MSCare, ' + first.split(' ')[0] + '!');
  });
}

/* ---------- OTP page: six boxes, code checked here ---------- */
function initOtp() {
  const form = $('#otpForm'); if (!form) return;
  let otp = readOtp();
  if (!otp) { location.replace('login.php'); return; }
  const boxes = $$('.otp-box'), err = $('#otpError');
  const draw = () => { $('#otpTarget').textContent = maskContact(otp.target); $('#otpCode').textContent = otp.code; };
  draw();
  if (boxes[0]) boxes[0].focus();
  boxes.forEach((box, i) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
      if (box.value && boxes[i + 1]) boxes[i + 1].focus();
    });
    box.addEventListener('keydown', e => { if (e.key === 'Backspace' && !box.value && boxes[i - 1]) boxes[i - 1].focus(); });
    box.addEventListener('paste', e => {
      e.preventDefault();
      const t = (e.clipboardData.getData('text') || '').replace(/[^0-9]/g, '').slice(0, 6);
      t.split('').forEach((ch, j) => { if (boxes[j]) boxes[j].value = ch; });
      (boxes[t.length] || boxes[5]).focus();
    });
  });
  const goLogin = msg => { kvDel(OTP_KEY); if (msg) flash(msg); location.href = 'login.php'; };
  form.addEventListener('submit', e => {
    e.preventDefault();
    const act = (e.submitter && e.submitter.value) || 'verify';
    if (act === 'cancel') return goLogin();
    if (act === 'resend') {
      otp.code = newCode(); otp.attempts = 3; otp.expires = Date.now() + 600000; kvSet(OTP_KEY, JSON.stringify(otp));
      draw(); boxes.forEach(b => b.value = ''); err.classList.add('hidden'); if (boxes[0]) boxes[0].focus();
      return showToast('New code sent to ' + maskContact(otp.target));
    }
    if (Date.now() > otp.expires) return goLogin('That code expired. Please log in again.');
    const code = boxes.map(b => b.value).join('');
    if (code.length < 6) return showFormError('otpError', 'Please enter all 6 digits.');
    if (code === otp.code) {
      kvSet(SESSION_KEY[otp.role], String(otp.id)); kvDel(OTP_KEY);
      flash(otp.welcome || 'Logged in successfully.');
      location.href = otp.role === 'admin' ? 'index.php' : 'patient-dashboard.php';
      return;
    }
    otp.attempts--;
    if (otp.attempts <= 0) return goLogin('Too many incorrect attempts. Please log in again.');
    kvSet(OTP_KEY, JSON.stringify(otp));
    showFormError('otpError', 'Incorrect code. ' + otp.attempts + ' attempt' + (otp.attempts === 1 ? '' : 's') + ' left.');
  });
}

/* ---------- patient profile ---------- */
function initProfile() {
  const form = $('#profileForm'); if (!form) return;
  form.addEventListener('submit', e => {
    e.preventDefault();
    const me = currentPatient(); if (!me) return;
    const f = form.elements, name = upperName(f.name.value), age = parseInt(f.age.value, 10) || 0;
    const email = f.email.value.trim(), phone = f.phone.value.trim(), gender = f.gender.value;
    let err = '';
    if (!name || !validNamePart(name) || name.length > 190) err = 'Please enter a valid name (letters only).';
    else if (age < 1 || age > 120) err = 'Please enter a valid age.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) err = 'Please enter a valid email address.';
    else if (!/^[0-9+\-\s()]{7,20}$/.test(phone)) err = 'Please enter a valid phone number.';
    else if (!['Female', 'Male', 'Prefer not to say', 'Other'].includes(gender)) err = 'Please choose a gender.';
    else if (DB.patients.some(x => x.id !== me.id && x.email.toLowerCase() === email.toLowerCase())) err = 'Another account already uses that email.';
    if (err) return showToast(err);
    if (name !== me.full_name) {
      me.full_name = name; me.last_name = ''; me.first_name = ''; me.middle_name = '';
      DB.appts.forEach(a => { if (a.patient_id === me.id) a.patient_name = name; });
    }
    Object.assign(me, { age: age, email: email, phone: phone, gender: gender });
    saveDB(); flash('Profile updated.'); location.href = 'patient-profile.php';
  });
}

/* ---------- admin: patients who registered on the patient site ---------- */
function initAdminPatients() {
  if (ROLE !== 'admin') return;
  const reg = DB.patients.filter(p => !p.is_demo).sort((a, b) => a.full_name.localeCompare(b.full_name));
  const sel = $('#naPatient');
  if (sel) reg.forEach(p => { const o = document.createElement('option'); o.value = 'db' + p.id; o.dataset.clinic = '1 2'; o.textContent = p.full_name; sel.appendChild(o); });
  const body = $('#patientsTable tbody');
  if (body) reg.forEach(p => {
    const ini = ((p.first_name || p.full_name).charAt(0) + (p.last_name || p.full_name.split(' ')[1] || '').charAt(0)).toUpperCase();
    const tr = document.createElement('tr'); tr.className = 'row-hover'; tr.dataset.clinic = '1 2';
    tr.innerHTML = '<td><div class="row-people"><div class="avatar-sm">' + esc(ini) + '</div><div><div class="name">' + esc(p.full_name) + '</div><div class="sub">' + esc(p.gender) + '</div></div></div></td>' +
      '<td>' + esc(p.email) + '<br><span style="color:var(--muted);font-size:11.5px;">' + esc(p.phone) + '</span></td><td>' + esc(p.age) + '</td><td>No visits yet</td><td></td>';
    body.appendChild(tr);
  });
}

/* ---------- DENTAL RECORD CHART ---------- */
function initChart() {
  const chart = $('#dchart'); if (!chart) return;
  const editable = chart.dataset.editable === 'true';
  const select = $('#chartPatient');
  const details = $('#toothDetails'), notesBox = $('#toothNotes');
  const session = getSession();
  let pid = (session && session.patientId) || 'p1';
  if (select) {
    const want = new URLSearchParams(location.search).get('patient');
    if (want && $('option[value="' + want + '"]', select)) select.value = want;
    pid = select.value;
  }
  let teeth = {}, selected = null;
  const num = el => parseInt(el.dataset.n, 10);

  function paint(el) {
    const code = teeth[num(el)];
    el.className = 'tooth' + (selected === num(el) ? ' sel' : '') + (code ? ' st-' + CODES[code].cat : '');
    $('.tc', el).textContent = code || '';
    el.title = 'Tooth #' + num(el) + (code ? ' — ' + code + ' (' + CODES[code].name + ')' : '');
  }
  function renderBoxes() {
    const boxes = { sbTR: [1, 5], sbTL: [2, 6], sbBL: [3, 7], sbBR: [4, 8] };
    Object.keys(boxes).forEach(id => {
      const box = $('#' + id); if (!box) return;
      const list = Object.keys(teeth).map(Number).filter(n => boxes[id].includes(Math.floor(n / 10))).sort((a, b) => a - b);
      box.innerHTML = list.map(n => '<span class="sb-chip st-' + CODES[teeth[n]].cat + '" title="' + CODES[teeth[n]].name + '">' + n + ' ' + teeth[n] + '</span>').join('');
    });
  }
  function renderFlagged() {
    const box = $('#flaggedList'); if (!box) return;
    const list = Object.keys(teeth).map(Number).sort((a, b) => a - b);
    box.innerHTML = list.length ? list.map(n =>
      '<button type="button" class="sb-chip st-' + CODES[teeth[n]].cat + '" data-flag="' + n + '">#' + n + ' · ' + teeth[n] + '</button>').join('')
      : '<span style="font-size:12.5px;color:var(--muted);">No flagged teeth.</span>';
    $$('[data-flag]', box).forEach(el => el.addEventListener('click', () => pick(parseInt(el.dataset.flag, 10))));
  }
  function renderDetails() {
    if (!selected) {
      details.innerHTML = '<div class="section-head"><h4>Tooth details</h4></div><div class="notif-empty" style="padding:30px 10px;">Select a tooth on the chart to see its details.</div>';
      notesBox.innerHTML = ''; return;
    }
    const code = teeth[selected], rec = CHART_DATA[pid].notes[selected];
    let html = '<div class="section-head"><h4>Tooth details</h4></div>' +
      '<p style="font-weight:700;font-size:16px;">Tooth #' + selected + '</p>' +
      '<p class="hint" style="margin:2px 0 14px;">' + toothInfo(selected) + '</p>' +
      '<div class="detail-row"><span>Status</span><span class="cat-text st-' + (code ? CODES[code].cat : 'healthy') + '">' + (code ? code + ' — ' + CODES[code].name : 'Healthy') + '</span></div>';
    if (rec) {
      html += '<div class="detail-row"><span>Procedure</span><span>' + rec.procedure + '</span></div>' +
              '<div class="detail-row"><span>Date</span><span>' + rec.date + '</span></div>' +
              '<div class="detail-row"><span>Dentist</span><span>' + rec.dentist + '</span></div>';
    } else {
      html += '<p style="font-size:12.5px;color:var(--muted);margin-top:12px;">No treatment logged for this tooth yet.</p>';
    }
    if (editable) {
      html += '<p style="font-size:13px;font-weight:700;color:var(--navy);margin-top:18px;">Update status</p>' +
        CODE_GROUPS.map(g => '<div class="code-group-title">' + g.title + '</div><div class="code-grid">' +
          g.codes.map(c => '<button type="button" class="code-btn' + (code === c ? ' active' : '') + '" data-code="' + c + '" title="' + CODES[c].name + '">' + c + '</button>').join('') + '</div>').join('') +
        '<button type="button" class="btn btn-ghost btn-sm" id="clearCode" style="margin-top:18px;">Clear status</button>';
    }
    details.innerHTML = html;
    notesBox.innerHTML = rec ? '<div class="section-head"><h4>Notes</h4></div><p style="font-size:13px;color:var(--muted);line-height:1.6;">' + rec.note + '</p>' : '';
    $$('.code-btn', details).forEach(b => b.addEventListener('click', () => {
      teeth[selected] = b.dataset.code;
      refresh(); showToast('Tooth #' + selected + ' marked ' + b.dataset.code + ' — ' + CODES[b.dataset.code].name + '.');
    }));
    const clr = $('#clearCode');
    if (clr) clr.addEventListener('click', () => { delete teeth[selected]; refresh(); showToast('Tooth #' + selected + ' status cleared.'); });
  }
  function refresh() {
    $$('.tooth', chart).forEach(paint);
    renderBoxes(); renderFlagged(); renderDetails();
  }
  function pick(n) { selected = n; refresh(); }
  function load(id) {
    pid = id; selected = null;
    const d = CHART_DATA[id], s = session || {};
    teeth = Object.assign({}, d.teeth);
    $('#dcName').textContent = (id === 'new' && s.name) ? s.name : d.name;
    $('#dcAge').textContent = (id === 'new' && s.age) ? s.age : (d.age || '—');
    $('#dcGender').textContent = (id === 'new' && s.gender) ? s.gender : (d.gender || '—');
    $('#dcDate').textContent = fmtDate(dayFromToday(0));
    refresh();
  }
  $$('.tooth', chart).forEach(el => el.addEventListener('click', () => pick(num(el))));
  if (select) select.addEventListener('change', () => load(select.value));
  load(pid);
}

/* ---------- BOOKING (patient) ---------- */
function slotStatus(date, time, dentist, patientId) {
  const hits = BOOKED.filter(b => dayFromToday(b.offset) === date && b.time === time)
    .concat((MS.booked || []).filter(b => b.date === date && b.time === time));
  if (hits.some(b => (b.patient || b.uid) === patientId)) return 'self';
  if (hits.some(b => b.dentist === dentist)) return 'dentist';
  return 'free';
}
function initBooking() {
  const form = $('#bookForm'); if (!form) return;
  const clinicEl = $('#bkClinic'), dateEl = $('#bkDate'), dentistEl = $('#bkDentist'), timeEl = $('#bkTime'), hint = $('#bkTimeHint'), btn = $('#bkSubmitBtn');
  const s = getSession(), pid = (s && s.uid) || 'p1';
  const clinic = () => parseInt(clinicEl.value, 10);
  dateEl.min = dayFromToday(0); dateEl.value = nextOpenFrom(clinic(), dayFromToday(7));

  function sync() {
    const c = clinic(), date = dateEl.value, dentist = dentistEl.value, keep = timeEl.value;
    if (!date || !clinicOpen(c, date)) {
      timeEl.innerHTML = ''; timeEl.disabled = true; btn.disabled = true;
      hint.textContent = CLINICS[c].label + ' is only open ' + CLINICS[c].daysText + '. Pick one of those days or switch clinics.';
      hint.style.color = 'var(--coral)'; return;
    }
    timeEl.innerHTML = TIME_SLOTS.map(t => {
      const st = slotStatus(date, t, dentist, pid);
      const label = st === 'dentist' ? t + ' — fully booked' : st === 'self' ? t + " — you're already booked" : t;
      return '<option value="' + t + '"' + (st !== 'free' ? ' disabled' : '') + (t === keep && st === 'free' ? ' selected' : '') + '>' + label + '</option>';
    }).join('');
    const free = TIME_SLOTS.filter(t => slotStatus(date, t, dentist, pid) === 'free');
    if (!free.length) {
      hint.textContent = dentist + ' has no open times on this date — try another date.';
      hint.style.color = 'var(--coral)'; timeEl.disabled = true; btn.disabled = true;
    } else {
      timeEl.disabled = false; btn.disabled = false; hint.style.color = 'var(--muted)';
      hint.textContent = (free.length < TIME_SLOTS.length ? 'Some times are already booked and shown grayed out. ' + free.length + ' still open. ' : 'All times are open on this date. ') + CLINICS[c].label + ' is open ' + CLINICS[c].daysText + '.';
      if (!timeEl.value) timeEl.value = free[0];
    }
  }
  clinicEl.addEventListener('change', () => { dateEl.value = nextOpenFrom(clinic(), dateEl.value || dayFromToday(0)); sync(); });
  dateEl.addEventListener('change', sync); dentistEl.addEventListener('change', sync); sync();

  form.addEventListener('submit', e => {
    e.preventDefault();
    const me = currentPatient(); if (!me) return location.replace('login.php');
    const f = form.elements, notes = (f.notes ? f.notes.value : '').trim().slice(0, 500);
    const err = checkBooking(me.id, 'You', parseInt(f.clinic.value, 10), f.service.value, f.dentist.value, f.date.value, f.time.value);
    if (err) { showToast(err); sync(); return; }
    addAppt({ patient_id: me.id, patient_name: me.full_name, service: f.service.value, clinic: parseInt(f.clinic.value, 10), dentist: f.dentist.value,
              date: f.date.value, time: f.time.value, notes: notes || null, status: 'Pending', created_by: 'patient' });
    flash('Appointment requested — we will confirm it shortly.');
    location.href = 'patient-appointments.php';
  });
}
/* show the patient's appointments from the database */
function initNewAppts() {
  const body = $('#apptTable tbody');
  if (!body || document.body.dataset.guard !== 'patient') return;
  const list = MS.appts || [];
  if (list.length) { const none = $('#apptNone'); if (none) none.remove(); }
  list.slice().reverse().forEach(a => {
    const tr = document.createElement('tr');
    tr.className = 'row-hover'; tr.dataset.status = a.status; tr.dataset.id = a.id;
    const open = a.status === 'Pending' || a.status === 'Confirmed';
    tr.innerHTML = '<td>' + esc(a.service) + '</td><td>Clinic ' + esc(a.clinic) + '</td><td>' + esc(a.dentist) + '</td><td>' + fmtDate(a.date) + '</td><td>' + esc(a.time) + '</td>' +
      '<td><span class="badge ' + STATUS_BADGE[a.status] + '">' + esc(a.status) + '</span></td>' +
      '<td>' + (open ? '<button class="btn btn-danger btn-sm" data-cancel-row data-row-action>Cancel</button>' : '') + '</td>';
    body.insertBefore(tr, body.firstChild);
  });
}
/* dashboard of a newly registered patient: built from their own bookings only */
function initNewDashboard() {
  const card = $('#nvCard'); if (!card) return;
  const list = (MS.appts || []).filter(a => (a.status === 'Pending' || a.status === 'Confirmed') && a.date >= dayFromToday(0));
  $('#nvUpcoming').textContent = list.length;
  const all = MS.appts || [];
  if (!all.length) return;
  if (list.length) {
    const n = list[0];
    $('#nvKicker').textContent = 'YOUR NEXT VISIT';
    $('#nvTitle').textContent = n.service + ' with ' + n.dentist;
    $('#nvWhen').textContent = fmtDate(n.date) + ' · ' + n.time + ' · Clinic ' + n.clinic;
  }
  const body = $('#nvTable tbody');
  all.filter(a => a.status !== 'Cancelled').slice(0, 4).forEach(a => {
    const tr = document.createElement('tr'); tr.className = 'row-hover';
    tr.innerHTML = '<td>' + esc(a.service) + '</td><td>Clinic ' + esc(a.clinic) + '</td><td>' + fmtDate(a.date) + ', ' + esc(a.time) + '</td><td><span class="badge ' + STATUS_BADGE[a.status] + '">' + esc(a.status) + '</span></td>';
    body.appendChild(tr);
  });
  $('#nvTable').classList.remove('hidden');
  const none = $('#nvEmpty'); if (none) none.remove();
}
/* bookings from patients (and ones the admin made) for this admin's clinic */
function initAdminNewAppts() {
  const body = $('#adminApptTable tbody');
  if (!body || document.body.dataset.guard !== 'admin') return;
  (MS.appts || []).slice().reverse().forEach(a => body.insertBefore(adminRow(a), body.firstChild));
}
function adminRow(a) {
  const name = a.patient || 'New patient';
  const tr = document.createElement('tr');
  tr.className = 'row-hover'; tr.dataset.status = a.status; tr.dataset.patient = name; tr.dataset.id = a.id;
  const btn = (title, attr, inner) => '<button type="button" class="icon-btn" title="' + title + '" ' + attr + ' data-row-action>' + inner + '</button>';
  let actions = '';
  if (a.status === 'Pending') actions = btn('Confirm', 'data-confirm-row', CHECK_SVG) + btn('Cancel', 'data-cancel-row', '×');
  else if (a.status === 'Confirmed') actions = btn('Mark completed', 'data-complete-row', CHECK_SVG) + btn('Cancel', 'data-cancel-row', '×');
  tr.innerHTML = '<td><div class="row-people"><div class="avatar-sm">' + esc(initials(name)) + '</div><span class="name">' + esc(name) + '</span></div></td>' +
    '<td>' + esc(a.service) + '</td><td>' + esc(a.dentist) + '</td><td>' + fmtDate(a.date) + '</td><td>' + esc(a.time) + '</td>' +
    '<td><span class="badge ' + STATUS_BADGE[a.status] + '">' + esc(a.status) + '</span></td>' +
    '<td>' + (actions ? '<div class="action-row">' + actions + '</div>' : '') + '</td>';
  return tr;
}

/* ---------- ADMIN: new appointment + log treatment ---------- */
function initAdminForms() {
  const apptForm = $('#newApptForm');
  if (apptForm) {
    const date = $('#naDate'), clinic = (getSession() && getSession().clinic) || 1;
    date.min = dayFromToday(0); date.value = nextOpenFrom(clinic, dayFromToday(1));
    apptForm.addEventListener('submit', e => {
      e.preventDefault();
      const sel = $('#naPatient'), name = sel.options[sel.selectedIndex].text;
      if (!clinicOpen(clinic, date.value)) return showToast(CLINICS[clinic].label + ' is only open ' + CLINICS[clinic].daysText + '.');
      const slot = slotStatus(date.value, $('#naTime').value, $('#naDentist').value, sel.value);
      if (slot === 'dentist') return showToast($('#naDentist').value + ' is already booked at that time.');
      if (slot === 'self') return showToast(name + ' already has an appointment at that time.');
      const submit = apptForm.querySelector('[type=submit]'); if (submit) submit.disabled = true;
      api({ action: 'create', patient: sel.value, service: $('#naType').value, dentist: $('#naDentist').value, date: date.value, time: $('#naTime').value })
        .then(r => {
          if (submit) submit.disabled = false;
          if (!r.ok) return showToast(r.error || 'Could not save the appointment.');
          const tr = adminRow(r.appt), body = $('#adminApptTable tbody');
          body.insertBefore(tr, body.firstChild); wireRowButtons(tr);
          (MS.booked = MS.booked || []).push({ date: r.appt.date, time: r.appt.time, dentist: r.appt.dentist, uid: sel.value });
          $('#apptModal').classList.remove('open');
          showToast('Appointment scheduled.');
        });
    });
  }
  const tForm = $('#treatForm');
  if (tForm) {
    $('#tDate').value = dayFromToday(0);
    tForm.addEventListener('submit', e => {
      e.preventDefault();
      const sel = $('#tPatient'), name = sel.options[sel.selectedIndex].text;
      const tr = document.createElement('tr');
      tr.className = 'row-hover';
      tr.innerHTML = '<td>' + fmtDate($('#tDate').value) + '</td><td>' + name + '</td><td>' + ($('#tProc').value || 'Procedure') + '</td><td>' + ($('#tTooth').value || '—') + '</td><td>' + $('#tDentist').value + '</td><td>₱' + (parseInt($('#tCost').value, 10) || 0).toLocaleString() + '</td>';
      const body = $('#treatTable tbody'); body.insertBefore(tr, body.firstChild);
      $('#treatModal').classList.remove('open');
      tForm.reset(); $('#tDate').value = dayFromToday(0);
      showToast('Treatment logged.');
    });
  }
}


/* ---------- PATIENT DENTAL RECORD (admin: view what patients filled in + approve their signature) ---------- */
function consentState(r) {
  const c = r && r.consent;
  return !c || !c.done ? 'none' : c.status === 'approved' ? 'approved' : c.status === 'rejected' ? 'rejected' : 'pending';
}
function initPatientRecord() {
  const root = $('#arRoot'); if (!root || ROLE !== 'admin') return;
  const adm = currentAdmin(); if (!adm) return;
  const sel = $('#arPatient');
  const list = DB.patients.filter(p => !p.is_demo || adm.clinic === 1).sort((a, b) => a.full_name.localeCompare(b.full_name));
  function fillSelect(keep) {
    sel.innerHTML = list.map(p => '<option value="' + p.id + '">' + esc(p.full_name) + (consentState(p.record) === 'pending' ? '  \u2014  signature needs approval' : '') + '</option>').join('');
    if (keep) sel.value = keep;
  }
  fillSelect();
  const want = new URLSearchParams(location.search).get('patient');
  if (want && list.some(p => String(p.id) === want)) sel.value = want;
  const tabs = $$('[data-ar-tab]', root), panels = $$('[data-ar-panel]', root);
  const show = n => { tabs.forEach(t => t.classList.toggle('active', t.dataset.arTab === n)); panels.forEach(p => p.classList.toggle('hidden', p.dataset.arPanel !== n)); };
  tabs.forEach(t => t.addEventListener('click', () => show(t.dataset.arTab)));
  const kv = (k, v, cls) => '<div class="' + (cls || '') + '"><div class="ar-k">' + k + '</div><div class="ar-v' + (v ? '' : ' empty') + (k === 'Email address' ? ' lc' : '') + '">' + (v ? esc(v) : 'Not provided') + '</div></div>';
  const dt = s => s ? fmtDate(s) : '';
  const chip = (cls, label, val) => '<span class="badge ' + cls + '">' + label + ': ' + val + '</span>';
  const CS = { approved: ['b-confirmed', 'Approved'], pending: ['b-pending', 'Waiting for approval'], rejected: ['b-cancelled', 'Rejected'], none: ['b-pending', 'Not done'] };

  function draw() {
    const p = list.find(x => String(x.id) === sel.value);
    if (!p) { $('#arCard').innerHTML = '<div class="ar-empty">No patients yet.</div>'; $('#arChips').innerHTML = ''; return; }
    const r = p.record || {}, i = r.info || {}, m = r.medical, c = r.consent, cs = consentState(r);
    $('#arChips').innerHTML = chip(m && m.done ? 'b-confirmed' : 'b-pending', 'Medical history', m && m.done ? 'Complete' : 'Not done') + chip(CS[cs][0], 'Informed consent', CS[cs][1]);

    $('#arInfo').innerHTML = '<p class="ar-eyebrow">Patient information record</p><div class="ar-grid">' +
      kv('Last name', p.last_name || '') + kv('First name', p.first_name || p.full_name) + kv('Middle name', p.middle_name || '') +
      kv('Birthdate', dt(i.birth)) + kv('Age', p.age ? String(p.age) : '') + kv('Sex', p.gender || '') +
      kv('Nickname', i.nickname) + kv('Religion', i.religion) + kv('Nationality', i.nationality) +
      kv('Home address', i.address, 'full') + kv('Occupation', i.occupation) + kv('Dental insurance', i.insurance) + kv('Effective date', dt(i.effective)) +
      kv('Home no.', i.homeNo) + kv('Office no.', i.officeNo) + kv('Fax no.', i.faxNo) +
      kv('Cell / mobile no.', p.phone || '') + kv('Email address', p.email || '', 'span2') + '</div>' +
      '<p class="ar-eyebrow sub">For minors</p><div class="ar-grid">' + kv('Parent / guardian name', i.guardian, 'span2') + kv('Parent / guardian occupation', i.guardianOcc) + '</div>' +
      '<p class="ar-eyebrow sub">Dental history</p><div class="ar-grid">' + kv('Previous dentist', i.prevDentist) + kv('Last dental visit', dt(i.lastVisit)) + '<div></div>' + kv('Reason for consultation', i.reason, 'full') + '</div>';

    if (!m) {
      $('#arMedical').innerHTML = '<div class="ar-empty">This patient has not filled in the medical history yet.</div>';
    } else {
      const alg = (m.allergies || []).filter(a => a !== 'None').map(a => a === 'Other' ? 'Other: ' + (m.allergy_other || '') : a);
      const con = (m.conditions || []).filter(a => a !== 'None').map(a => a === 'Other' ? 'Other: ' + (m.cond_other || '') : a);
      const tags = (arr, warn) => '<div class="ar-tags">' + arr.map(t => '<span class="ar-tag' + (warn && /diabet/i.test(t) ? ' warn' : '') + '">' + esc(t) + '</span>').join('') + '</div>';
      const qa = (q, k) => m[k] ? '<div class="ar-qa"><span>' + q + (m[k] === 'Yes' && m[k + '_d'] ? '<br><span style="color:var(--muted);font-size:12.5px;text-transform:uppercase;">' + esc(m[k + '_d']) + '</span>' : '') + '</span><span class="a' + (m[k] === 'Yes' && k !== 'good' ? ' yes' : '') + '">' + m[k] + '</span></div>' : '';
      $('#arMedical').innerHTML =
        '<div class="ar-alert' + (alg.length ? '' : ' ok') + '"><h5>Allergies</h5>' + (alg.length ? tags(alg) : '<span style="font-size:13px;color:var(--green);font-weight:600;">No known allergies</span>') + '</div>' +
        '<div class="ar-alert' + (con.length ? '' : ' ok') + '" style="margin-top:12px;"><h5>Medical conditions</h5>' + (con.length ? tags(con, true) : '<span style="font-size:13px;color:var(--green);font-weight:600;">None reported</span>') + '</div>' +
        '<p class="ar-eyebrow sub">Physician</p><div class="ar-grid">' + kv('Name of physician', m.phys) + kv('Specialty', m.spec) + kv('Office no.', m.physNo) + kv('Office address', m.physAddr, 'full') + '</div>' +
        '<p class="ar-eyebrow sub">Health questions</p>' +
        qa('Are you in good health?', 'good') + qa('Under medical treatment now?', 'treat') + qa('Serious illness or surgical operation?', 'ill') + qa('Ever been hospitalized?', 'hosp') +
        qa('Taking any medication?', 'meds') + qa('Uses tobacco products?', 'tobacco') + qa('Uses alcohol, cocaine or other dangerous drugs?', 'alcohol') +
        (m.preg ? qa('Pregnant?', 'preg') + qa('Nursing?', 'nurse') + qa('Taking birth control pills?', 'pills') : '') +
        '<div class="ar-grid" style="margin-top:18px;">' + kv('Blood type', m.blood) + kv('Blood pressure', m.bp) + kv('Last updated', dt(m.date)) + '</div>';
    }

    /* informed consent + signature approval */
    const tries = r.sigTries || 0;
    let h = '<p class="ar-eyebrow">Informed consent</p>';
    if (c && c.done) {
      h += '<div class="ar-sign"><span class="badge ' + CS[cs][0] + '">' + CS[cs][1] + '</span>' +
        (c.signature ? '<img class="ar-sigimg" src="' + esc(c.signature) + '" alt="Signature">' : '<div class="name">' + esc(c.name) + '</div>') +
        '<div style="font-size:12.5px;color:var(--muted);">' + esc(c.name) + ' &middot; Signed on ' + fmtDate(c.date) + '</div>' +
        (cs === 'approved' ? '<div style="font-size:12.5px;color:var(--green);font-weight:600;margin-top:6px;">Approved by ' + esc(c.reviewedBy || 'the clinic') + (c.reviewedAt ? ' on ' + fmtDate(c.reviewedAt) : '') + '</div>' : '') +
        (cs === 'rejected' ? '<div style="font-size:12.5px;color:var(--coral);font-weight:600;margin-top:6px;">Rejected' + (c.note ? ': ' + esc(c.note) : '') + '. Waiting for the patient to upload it again.</div>' : '') + '</div>';
      if (cs === 'pending' || cs === 'approved') {
        h += '<div class="ar-review"><p><b>' + (cs === 'pending' ? 'Check this signature.' : 'Changed your mind?') + '</b> Make sure it really is this patient\u2019s own signature. ' +
          (cs === 'pending' ? 'The patient cannot book an appointment until you approve it.' : 'Rejecting it locks booking again until the patient uploads a new one.') + '</p>' +
          (cs === 'pending' ? '<button type="button" class="btn btn-primary btn-sm" id="arApprove">Approve signature</button> ' : '') +
          '<div class="ar-reject"><input id="arNote" placeholder="Reason if rejected (e.g. signature is not clear)"><button type="button" class="btn btn-danger btn-sm" id="arReject">Reject</button></div></div>';
      }
      h += '<p class="hint" style="margin-top:12px;">The patient agreed to examination and treatment, medications, X-rays, extractions, restorations, and to MSCare keeping their records.</p>';
    } else {
      h += '<div class="ar-empty">This patient has not signed the informed consent yet.</div>';
    }
    if (tries > 0) {
      h += '<div class="ar-tries ' + (tries >= 3 ? 'bad' : '') + '">Signature upload tries used: <b>' + Math.min(tries, 3) + ' of 3</b>' + (tries >= 3 ? ' \u2014 the patient is locked out of uploading.' : '') +
        ' <button type="button" class="btn btn-ghost btn-sm" id="arResetTries">Reset tries</button></div>';
    }
    $('#arConsent').innerHTML = h;

    const review = (status, msg) => {
      if (!p.record || !p.record.consent) return;
      const cc = p.record.consent;
      cc.status = status; cc.reviewedAt = dayFromToday(0); cc.reviewedBy = adm.display_name;
      if (status === 'rejected') { cc.note = ($('#arNote').value || '').trim().slice(0, 140); p.record.sigTries = 0; } else delete cc.note;
      saveDB(); const keep = sel.value; fillSelect(keep); draw(); show('consent'); showToast(msg);
    };
    const ap = $('#arApprove'), rj = $('#arReject'), rs = $('#arResetTries');
    if (ap) ap.addEventListener('click', () => review('approved', 'Signature approved. The patient can now book.'));
    if (rj) rj.addEventListener('click', () => review('rejected', 'Signature rejected. The patient must upload a new one.'));
    if (rs) rs.addEventListener('click', () => { p.record.sigTries = 0; saveDB(); draw(); show('consent'); showToast('Upload tries reset.'); });
  }
  sel.addEventListener('change', draw); draw();
}

/* ---------- start ---------- */
document.addEventListener('DOMContentLoaded', () => {
  // Live PostgreSQL Mode: disabled legacy localStorage mockup injectors
  // initAdminPatients();
  // guardPage();
  // initNewAppts();
  // initNewDashboard();
  // initAdminNewAppts();
  initCommon();
  initUppercase();
  // initLogin();
  // initRegister();
  // initOtp();
  // initProfile();
  // initChart();
  // initPatientRecord();
  // initBooking();
  // initAdminForms();
});
