/* MSCare - confirm-password match hint.
   Add data-pw-match="#otherId" on the confirm input. The password rules themselves are
   enforced by the server (includes/password_policy.php), which shows the exact reason. */
(function () {
  var css = '.pw-match{margin:6px 0 0;font-size:.82rem;min-height:1em}.pw-match.bad{color:#C2432B}.pw-match.ok{color:#168078}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function initMatch(input) {
    var other = document.querySelector(input.getAttribute('data-pw-match'));
    if (!other) return;
    var msg = document.createElement('p'); msg.className = 'pw-match'; msg.setAttribute('aria-live', 'polite');
    var host = input.closest('.input-icon') || input;
    host.parentNode.insertBefore(msg, host.nextSibling);
    function paint() {
      if (input.value === '') { msg.textContent = ''; msg.className = 'pw-match'; input.setCustomValidity(''); return; }
      var same = input.value === other.value;
      msg.textContent = same ? 'Passwords match.' : 'Passwords do not match yet.';
      msg.className = 'pw-match ' + (same ? 'ok' : 'bad');
      input.setCustomValidity(same ? '' : 'Passwords do not match.');
    }
    input.addEventListener('input', paint); other.addEventListener('input', paint);
  }

  function boot() { Array.prototype.forEach.call(document.querySelectorAll('input[data-pw-match]'), initMatch); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
