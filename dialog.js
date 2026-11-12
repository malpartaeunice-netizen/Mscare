/* MSCare - in-page dialogs & toasts (replace the browser's "localhost says" alert/confirm/prompt).
   msConfirm({title, message, confirmText, cancelText, danger})        -> Promise<boolean>
   msPrompt({title, message, label, value, placeholder, confirmText, required, multiline}) -> Promise<string|null>
   msChoice({title, message, options:[{value,label,hint}], value, confirmText}) -> Promise<string|null>
   msToast(message, 'error'|'ok')                                      */
(function () {
  if (window.msConfirm) return;

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function open(build) {
    return new Promise(function (resolve) {
      var prev = document.activeElement;
      var back = el('div', 'ms-dlg-back');
      var box = el('div', 'ms-dlg');
      box.setAttribute('role', 'dialog');
      box.setAttribute('aria-modal', 'true');
      back.appendChild(box);

      var done = false;
      function close(val) {
        if (done) return;
        done = true;
        document.removeEventListener('keydown', onKey, true);
        back.classList.add('out');
        setTimeout(function () { back.remove(); if (prev && prev.focus) prev.focus(); }, 120);
        resolve(val);
      }
      var api = build(box, close);
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); close(api.cancelValue); }
        else if (e.key === 'Tab') {            // keep focus inside the dialog
          var f = box.querySelectorAll('button, input, textarea, select');
          if (!f.length) return;
          var first = f[0], last = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
          else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
      }
      back.addEventListener('mousedown', function (e) { if (e.target === back) close(api.cancelValue); });
      document.addEventListener('keydown', onKey, true);
      document.body.appendChild(back);
      (api.focus || function () {})();
    });
  }

  function head(box, o) {
    if (o.icon === 'warn') {
      var ic = el('div', 'ms-dlg-ico');
      ic.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>';
      box.appendChild(ic);
    }
    box.appendChild(el('h3', 'ms-dlg-title', o.title || 'Please confirm'));
    if (o.message) box.appendChild(el('p', 'ms-dlg-msg', o.message));
  }

  window.msConfirm = function (o) {
    o = o || {};
    return open(function (box, close) {
      head(box, o);
      var row = el('div', 'ms-dlg-actions');
      var no = el('button', 'ms-dlg-btn ghost', o.cancelText || 'Cancel'); no.type = 'button';
      var yes = el('button', 'ms-dlg-btn ' + (o.danger ? 'danger' : 'primary'), o.confirmText || 'Confirm'); yes.type = 'button';
      no.onclick = function () { close(false); };
      yes.onclick = function () { close(true); };
      row.appendChild(no); row.appendChild(yes); box.appendChild(row);
      return { cancelValue: false, focus: function () { (o.danger ? no : yes).focus(); } };
    });
  };

  window.msPrompt = function (o) {
    o = o || {};
    return open(function (box, close) {
      head(box, o);
      var input = o.multiline === false ? el('input', 'ms-dlg-input') : el('textarea', 'ms-dlg-input');
      if (o.multiline === false) input.type = 'text'; else input.rows = 3;
      if (o.label) { var lb = el('label', 'ms-dlg-label', o.label); box.appendChild(lb); }
      input.value = o.value || '';
      input.placeholder = o.placeholder || '';
      box.appendChild(input);
      var err = el('div', 'ms-dlg-err'); box.appendChild(err);
      var row = el('div', 'ms-dlg-actions');
      var no = el('button', 'ms-dlg-btn ghost', o.cancelText || 'Cancel'); no.type = 'button';
      var yes = el('button', 'ms-dlg-btn ' + (o.danger ? 'danger' : 'primary'), o.confirmText || 'OK'); yes.type = 'button';
      function submit() {
        var v = input.value.trim();
        if (o.required !== false && !v) { err.textContent = 'Please fill this in.'; input.focus(); return; }
        close(v);
      }
      no.onclick = function () { close(null); };
      yes.onclick = submit;
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); } });
      row.appendChild(no); row.appendChild(yes); box.appendChild(row);
      return { cancelValue: null, focus: function () { input.focus(); input.select(); } };
    });
  };

  window.msChoice = function (o) {
    o = o || {};
    return open(function (box, close) {
      head(box, o);
      var list = el('div', 'ms-dlg-choices');
      var picked = o.value != null ? o.value : (o.options[0] && o.options[0].value);
      var btns = [];
      o.options.forEach(function (opt) {
        var b = el('button', 'ms-dlg-choice' + (opt.value === picked ? ' on' : ''));
        b.type = 'button';
        b.setAttribute('role', 'radio');
        b.setAttribute('aria-checked', opt.value === picked ? 'true' : 'false');
        b.appendChild(el('strong', null, opt.label));
        if (opt.hint) b.appendChild(el('small', null, opt.hint));
        b.onclick = function () {
          picked = opt.value;
          btns.forEach(function (x) { x.classList.remove('on'); x.setAttribute('aria-checked', 'false'); });
          b.classList.add('on'); b.setAttribute('aria-checked', 'true');
        };
        b.ondblclick = function () { close(picked); };
        btns.push(b); list.appendChild(b);
      });
      list.setAttribute('role', 'radiogroup');
      box.appendChild(list);
      var row = el('div', 'ms-dlg-actions');
      var no = el('button', 'ms-dlg-btn ghost', o.cancelText || 'Cancel'); no.type = 'button';
      var yes = el('button', 'ms-dlg-btn primary', o.confirmText || 'Save'); yes.type = 'button';
      no.onclick = function () { close(null); };
      yes.onclick = function () { close(picked); };
      row.appendChild(no); row.appendChild(yes); box.appendChild(row);
      return { cancelValue: null, focus: function () { (list.querySelector('.on') || yes).focus(); } };
    });
  };

  window.msToast = function (message, type) {
    var t = el('div', 'ms-toast' + (type === 'error' ? ' err' : ''), message);
    t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('out'); setTimeout(function () { t.remove(); }, 200); }, type === 'error' ? 5000 : 3200);
  };
})();
