<?php
/*
 * Framework Manager
 * Copyright (C) 2026 Amaury Lesplingart <https://intheopen.eu>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License
 * for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */
/**
 * Framework Manager — Login Page
 */
require_once __DIR__ . '/includes/auth.php';

initDataDir();

// First run — no admin configured yet: go to the setup wizard.
if (!isInstalled()) {
    header('Location: setup.php');
    exit;
}

startSession();

$productName = function_exists('profileValue') ? profileValue('product_name', 'Framework Manager') : 'Framework Manager';

// Redirect if already logged in
if (!empty($_SESSION['user_id']) && getCurrentUser()) {
    header('Location: app.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — <?= htmlspecialchars($productName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,300;1,6..72,400&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

  :root {
    --bg: #fff;
    --bg2: #F5F5F7;
    --text1: #1D1D1F;
    --text2: #6E6E73;
    --text3: #AEAEB2;
    --border: #E5E5EA;
    --accent: #0071E3;
    --accent-hover: #0077ED;
    --accent-soft: rgba(0,113,227,.06);
    --check: #34C759;
    --r: 14px;
    --r2: 10px;
    --ease: cubic-bezier(.4,0,.2,1);
    --serif: 'Newsreader', Georgia, serif;
    --sans: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', sans-serif;
  }

  html { scroll-behavior: smooth; font-size: 16px; }
  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text1);
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }
  ::selection { background: rgba(0,113,227,.15); }

  /* NAV */
  nav {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    padding: 12px 28px;
    display: flex; align-items: center; justify-content: space-between;
    background: rgba(255,255,255,.72);
    backdrop-filter: saturate(180%) blur(20px);
    -webkit-backdrop-filter: saturate(180%) blur(20px);
    border-bottom: 0.5px solid var(--border);
  }
  .nav-logo {
    display: flex; align-items: center; gap: 8px;
    text-decoration: none; color: var(--text1);
    font-size: .9375rem; font-weight: 600; letter-spacing: -.01em;
  }
  .logo-mark {
    width: 24px; height: 24px; border-radius: 6px;
    background: #EB4B98; color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: .6875rem; font-weight: 700;
  }
  .nav-brand-name {
    font-size: .9375rem; font-weight: 600; letter-spacing: -.015em;
  }

  /* MAIN */
  main {
    flex: 1; display: flex; align-items: center; justify-content: center;
    padding: 100px 24px 60px;
    position: relative; overflow: hidden;
  }

  main::before {
    content: ''; position: absolute; top: -20%; left: 50%;
    transform: translateX(-50%); width: 700px; height: 700px;
    background: radial-gradient(circle, var(--accent-soft) 0%, transparent 65%);
    pointer-events: none; animation: breathe 8s ease-in-out infinite;
  }

  @keyframes breathe {
    0%, 100% { opacity: 0.5; transform: translateX(-50%) scale(1); }
    50% { opacity: 1; transform: translateX(-50%) scale(1.06); }
  }

  .login-wrap {
    width: 100%; max-width: 400px; position: relative; z-index: 1;
    animation: fadeUp .6s var(--ease) both;
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .login-card {
    background: rgba(255,255,255,.85);
    backdrop-filter: saturate(180%) blur(20px);
    -webkit-backdrop-filter: saturate(180%) blur(20px);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 40px;
    box-shadow: 0 2px 40px rgba(0,0,0,.06), 0 1px 3px rgba(0,0,0,.04);
  }

  .login-header { margin-bottom: 32px; }
  .login-header h1 {
    font-family: var(--serif); font-size: 1.75rem; font-weight: 400;
    letter-spacing: -.03em; margin-bottom: 6px;
  }
  .login-header p {
    font-size: .875rem; color: var(--text2);
  }

  .form-group { margin-bottom: 16px; }
  .form-group label {
    display: block; font-size: .8125rem; font-weight: 500;
    color: var(--text1); margin-bottom: 7px;
  }
  .form-group input {
    width: 100%; padding: 11px 14px;
    border: 1px solid var(--border); border-radius: var(--r2);
    font-size: .9375rem; font-family: var(--sans);
    color: var(--text1); background: var(--bg);
    transition: border-color .15s var(--ease), box-shadow .15s var(--ease);
    outline: none;
  }
  .form-group input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(0,113,227,.12);
  }
  .form-group input::placeholder { color: var(--text3); }

  .btn-submit {
    width: 100%; padding: 12px 24px; margin-top: 8px;
    background: var(--accent); color: #fff;
    border: none; border-radius: 980px;
    font-size: .9375rem; font-weight: 500;
    font-family: var(--sans); cursor: pointer;
    transition: all .15s var(--ease);
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }
  .btn-submit:hover { background: var(--accent-hover); }
  .btn-submit:active { transform: scale(.98); }
  .btn-submit:disabled { opacity: .6; cursor: not-allowed; transform: none; }

  .error-msg {
    margin-top: 14px; padding: 12px 14px;
    background: rgba(255,59,48,.06); border-radius: var(--r2);
    border: 1px solid rgba(255,59,48,.15);
    font-size: .8125rem; color: #c00; display: none;
  }
  .error-msg.visible { display: block; }

  .spinner {
    width: 16px; height: 16px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin .6s linear infinite;
    display: none;
  }
  @keyframes spin { to { transform: rotate(360deg); } }
  .loading .spinner { display: block; }
  .loading .btn-text { display: none; }

  /* Footer */
  .login-footer {
    text-align: center; margin-top: 24px;
    font-size: .75rem; color: var(--text3);
  }
</style>
</head>
<body>

<nav>
  <a href="index.php" class="nav-logo">
    <span class="logo-mark">D</span>
    <span class="nav-brand-name">MANAGER</span>
  </a>
</nav>

<main>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-header">
        <h1>Welcome back</h1>
        <p>Sign in to <?= htmlspecialchars($productName) ?></p>
      </div>

      <form id="loginForm" autocomplete="on" novalidate>
        <div class="form-group">
          <label for="username">Username</label>
          <input
            type="text" id="username" name="username"
            placeholder="Enter your username"
            autocomplete="username"
            required
          >
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input
            type="password" id="password" name="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
          >
        </div>

        <div class="error-msg" id="errorMsg"></div>

        <button type="submit" class="btn-submit" id="submitBtn">
          <span class="spinner"></span>
          <span class="btn-text">Sign In</span>
        </button>
      </form>

    </div>
    <div class="login-footer">© Amaury Lesplingart <?= date('Y') ?> <br> Made in 🇫🇮 with ❤️ </div>
  </div>
</main>

<script>
  const form = document.getElementById('loginForm');
  const submitBtn = document.getElementById('submitBtn');
  const errorMsg = document.getElementById('errorMsg');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    if (!username || !password) {
      showError('Please enter your username and password.');
      return;
    }

    setLoading(true);
    hideError();

    try {
      const res = await fetch('api.php?action=login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password }),
      });
      const data = await res.json();
      if (data.success) {
        window.location.href = 'app.php';
      } else {
        showError(data.error || 'Invalid credentials. Please try again.');
      }
    } catch (err) {
      showError('Network error. Please try again.');
    } finally {
      setLoading(false);
    }
  });

  function setLoading(v) {
    submitBtn.disabled = v;
    submitBtn.classList.toggle('loading', v);
  }

  function showError(msg) {
    errorMsg.textContent = msg;
    errorMsg.classList.add('visible');
  }

  function hideError() {
    errorMsg.classList.remove('visible');
  }

  // Focus username on load
  document.getElementById('username').focus();
</script>
</body>
</html>
