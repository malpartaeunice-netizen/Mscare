<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify code | MSCare Dental Clinic</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body data-otp-role="admin">
<div class="center-screen">
  <div class="login-wrap otp-wrap">
      <div class="login-hero">
        <div class="brand">
          <svg class="tooth-mark" viewBox="0 0 24 24" fill="none" stroke="#5FD0C6" stroke-width="1.6"><path d="M12 3c-2.2 0-3 1.1-4.5 1.1S4.8 3 3.8 4.4c-1 1.5-.8 4 0 6.3.7 2 1.3 3.6 1.6 6 .2 1.7.9 3.3 1.9 3.3.9 0 1-1.6 1.2-3 .2-1.4.6-2.4 1.5-2.4s1.3 1 1.5 2.4c.2 1.4.3 3 1.2 3 1 0 1.7-1.6 1.9-3.3.3-2.4.9-4 1.6-6 .8-2.3 1-4.8 0-6.3C15.2 3 14 4.1 12.5 4.1S14.2 3 12 3Z"/></svg>
          <span>MSCare</span>
        </div>
        <h1>Let&rsquo;s confirm it&rsquo;s you.</h1>
      </div>
    <div class="login-form-side">
      <h3>Enter verification code</h3>
      <p class="sub">We sent a 6-digit code to <b id="otpTarget"></b>.</p>
      <div class="login-error hidden" id="otpError"></div>
      <form id="otpForm" novalidate>
        
        <input type="hidden" name="code" id="otpValue">
        <div class="otp-boxes">
          <input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code">
          <input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code">
          <input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code">
          <input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code">
          <input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code">
          <input type="text" inputmode="numeric" maxlength="1" class="otp-box" autocomplete="one-time-code">
        </div>
        <button type="submit" name="action" value="verify" class="btn btn-primary btn-block" style="margin-top:20px;">Verify &amp; continue <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
      </form>
      <p class="auth-switch">Didn&rsquo;t get it? <button type="submit" form="otpForm" name="action" value="resend" id="resendOtp" formnovalidate>Resend code</button></p>
      <p class="auth-switch"><button type="submit" form="otpForm" name="action" value="cancel" id="cancelOtp" formnovalidate>Use a different account</button></p>
      <div class="demo">
        Demo code (no real SMS/email is sent): <b id="otpCode"></b>
      </div>
    </div>
  </div>
</div>
<script src="script.js?v=20261027a"></script>
</body>
</html>
