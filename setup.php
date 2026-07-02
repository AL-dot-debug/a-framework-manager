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
 * Framework Manager — First-run Setup Wizard
 *
 * Runs once, before any admin exists. Creates the first admin account, the
 * framework profile + first sub-framework, and verifies the environment
 * (PHP version, extensions, writable + protected data directory).
 */
require_once __DIR__ . '/includes/auth.php';

initDataDir();

// Already set up? Never expose setup again.
if (isInstalled()) {
    header('Location: index.php');
    exit;
}

/** Environment / security checks. Each: [label, ok, detail, critical]. */
function setupChecks(): array {
    $c = [];
    $c[] = ['PHP version ≥ 8.1', version_compare(PHP_VERSION, '8.1', '>='), 'Found ' . PHP_VERSION, true];
    foreach (['json' => true, 'openssl' => true, 'curl' => true, 'mbstring' => false] as $ext => $critical) {
        $c[] = ["PHP extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing', $critical];
    }
    $c[] = ['data/ is writable', is_writable(DATA_DIR), DATA_DIR, true];
    $c[] = ['data/releases/ is writable', is_writable(RELEASES_DIR), RELEASES_DIR, true];
    $c[] = ['data/ protected from web access', file_exists(DATA_DIR . '/.htaccess'), 'data/.htaccess deny rule', false];
    $c[] = ['config writable (runtime overrides)', is_writable(DATA_DIR), 'data/config.json', true];
    return $c;
}

function setupSlug(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_');
}
function setupDash(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

$checks = setupChecks();
$criticalFail = false;
foreach ($checks as $ck) { if ($ck[3] && !$ck[1]) $criticalFail = true; }

$errors = [];
$vals = [
    'admin_name' => '', 'admin_user' => '', 'admin_email' => '',
    'product_name' => 'Framework Manager', 'framework_name' => '', 'org_name' => '',
    'sf_label' => '', 'sf_prefix' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($vals as $k => $_) { $vals[$k] = trim($_POST[$k] ?? ''); }
    $pw  = (string)($_POST['admin_pass'] ?? '');
    $pw2 = (string)($_POST['admin_pass2'] ?? '');

    if ($criticalFail) $errors[] = 'Resolve the critical environment checks before continuing.';
    if ($vals['admin_name'] === '')  $errors[] = 'Admin display name is required.';
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,}$/', $vals['admin_user'])) $errors[] = 'Username must be ≥ 3 chars (letters, digits, . _ -).';
    if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pw !== $pw2) $errors[] = 'Passwords do not match.';
    if ($vals['admin_email'] !== '' && !filter_var($vals['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Admin email is not a valid address.';
    if ($vals['framework_name'] === '') $errors[] = 'Framework name is required.';
    if ($vals['sf_label'] === '') $errors[] = 'First sub-framework label is required.';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]{1,9}$/', $vals['sf_prefix'])) $errors[] = 'Sub-framework ID prefix must be 2–10 letters/digits starting with a letter (e.g. TAC).';

    if (!$errors) {
        // 1. First admin account (replaces any default users).
        $admin = [
            'id'         => generateUUID(),
            'username'   => $vals['admin_user'],
            'password'   => password_hash($pw, PASSWORD_DEFAULT),
            'name'       => $vals['admin_name'],
            'email'      => $vals['admin_email'] ?: ($vals['admin_user'] . '@' . 'framework.local'),
            'role'       => 'admin',
            'tsc_role'   => 'president',
            'created_at' => date('c'),
        ];
        writeJSON(USERS_FILE, [$admin]);

        // 2. Framework profile + first sub-framework (technical fields derived).
        $defaults = require CONFIG_FILE;
        $profile  = $defaults['profile'];
        $fwSlug   = setupDash($vals['framework_name']) ?: 'framework';
        $sfSlug   = setupSlug($vals['sf_label']) ?: 'primary';
        $profile['product_name']   = $vals['product_name'] ?: 'Framework Manager';
        $profile['framework_name'] = $vals['framework_name'];
        $profile['org_name']       = $vals['org_name'] ?: $vals['framework_name'];
        $profile['org_short']      = $vals['org_name'] ? $vals['org_name'] : $vals['framework_name'];
        $profile['source_name']    = strtoupper($fwSlug);
        $profile['artifact_slug']  = $fwSlug . '-framework';
        $profile['stix_property_prefix']  = 'x_' . str_replace('-', '_', $fwSlug);
        $profile['extension_definition_id']   = 'extension-definition--' . generateUUID();
        $profile['extension_definition_name'] = $vals['framework_name'] . ' Membership';
        $profile['identity_ref']   = 'identity--' . generateUUID();
        $profile['subframeworks']  = [
            $sfSlug => [
                'label'           => $vals['sf_label'],
                'id_prefix'       => strtoupper($vals['sf_prefix']),
                'kill_chain_name' => $fwSlug . '-' . $sfSlug,
                'levels' => [
                    'tactic'       => ['label' => 'Tactic',        'plural' => 'Tactics'],
                    'technique'    => ['label' => 'Technique',     'plural' => 'Techniques'],
                    'subtechnique' => ['label' => 'Sub-technique', 'plural' => 'Sub-techniques'],
                ],
            ],
        ];
        saveProfile($profile);

        // 3. Re-seed the framework bundle so its extension-definition matches the new profile.
        @unlink(FRAMEWORK_FILE);
        ensureDataProtection();
        if (file_exists(SEED_SOURCE)) { copy(SEED_SOURCE, FRAMEWORK_FILE); }
        else { writeJSON(FRAMEWORK_FILE, ['type' => 'bundle', 'id' => 'bundle--' . generateUUID(), 'objects' => []]); }
        ensureExtensionDefinition();

        // 4. Mark installed and sign the admin in.
        setInstalled();
        startSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $admin['id'];
        header('Location: app.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup — Framework Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
  body { background: var(--bg2, #f5f5f7); }
  .setup-wrap { max-width: 720px; margin: 40px auto; padding: 0 20px 60px; }
  .setup-head { text-align: center; margin-bottom: 28px; }
  .setup-head h1 { font-family: 'Newsreader', serif; font-weight: 400; font-size: 2rem; margin: 0 0 6px; }
  .setup-head p { color: var(--text-muted, #6b6b70); margin: 0; }
  .setup-card { background: #fff; border: 1px solid var(--border, #e5e5ea); border-radius: 14px; padding: 26px; margin-bottom: 20px; }
  .setup-card h2 { font-size: 1rem; font-weight: 600; margin: 0 0 16px; }
  .chk { display: flex; align-items: center; gap: 10px; padding: 5px 0; font-size: .875rem; }
  .chk .dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
  .chk small { color: var(--text-muted, #6b6b70); margin-left: auto; }
  .err-box { background: rgba(255,59,48,.08); border: 1px solid rgba(255,59,48,.3); color: #c22; border-radius: 8px; padding: 12px 14px; margin-bottom: 20px; font-size: .875rem; }
  .err-box ul { margin: 4px 0 0; padding-left: 18px; }
</style>
</head>
<body>
  <div class="setup-wrap">
    <div class="setup-head">
      <h1>Welcome</h1>
      <p>Let's set up your Framework Manager instance. This runs only once.</p>
    </div>

    <?php if ($errors): ?>
      <div class="err-box"><strong>Please fix the following:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <!-- Environment checks -->
    <div class="setup-card">
      <h2>System &amp; security checks</h2>
      <?php foreach ($checks as [$label, $ok, $detail, $critical]): ?>
        <div class="chk">
          <span class="dot" style="background: <?= $ok ? '#34c759' : ($critical ? '#ff3b30' : '#ff9f0a') ?>"></span>
          <span><?= htmlspecialchars($label) ?><?= (!$ok && !$critical) ? ' <em style="color:#b0872a">(recommended)</em>' : '' ?></span>
          <small><?= htmlspecialchars($detail) ?></small>
        </div>
      <?php endforeach; ?>
      <?php if ($criticalFail): ?>
        <div class="err-box" style="margin:14px 0 0">A critical check failed. Fix it (e.g. <code>chmod -R 775 data/ &amp;&amp; chown -R www-data:www-data data/</code>) and reload this page.</div>
      <?php endif; ?>
    </div>

    <form method="POST">
      <!-- Admin account -->
      <div class="setup-card">
        <h2>Administrator account</h2>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full name</label>
            <input class="form-input" name="admin_name" value="<?= htmlspecialchars($vals['admin_name']) ?>" placeholder="Ada Lovelace" required>
          </div>
          <div class="form-group">
            <label class="form-label">Username</label>
            <input class="form-input" name="admin_user" value="<?= htmlspecialchars($vals['admin_user']) ?>" placeholder="admin" required>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Email <span class="text-muted" style="font-weight:400">(optional)</span></label>
          <input class="form-input" type="email" name="admin_email" value="<?= htmlspecialchars($vals['admin_email']) ?>" placeholder="you@example.com">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Password</label>
            <input class="form-input" type="password" name="admin_pass" placeholder="At least 8 characters" required>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm password</label>
            <input class="form-input" type="password" name="admin_pass2" placeholder="Repeat password" required>
          </div>
        </div>
      </div>

      <!-- Framework -->
      <div class="setup-card">
        <h2>Framework</h2>
        <div class="form-group">
          <label class="form-label">Framework name</label>
          <input class="form-input" name="framework_name" value="<?= htmlspecialchars($vals['framework_name']) ?>" placeholder="e.g. My Threat Framework" required>
          <div class="text-xs text-muted" style="margin-top:4px">The knowledge base this instance manages. Technical values (STIX namespace, artefact slug, IDs) are derived automatically — you can refine everything later in Settings.</div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Organisation name <span class="text-muted" style="font-weight:400">(optional)</span></label>
            <input class="form-input" name="org_name" value="<?= htmlspecialchars($vals['org_name']) ?>" placeholder="e.g. The Foundation">
          </div>
          <div class="form-group">
            <label class="form-label">Product name</label>
            <input class="form-input" name="product_name" value="<?= htmlspecialchars($vals['product_name']) ?>" placeholder="Framework Manager">
          </div>
        </div>
      </div>

      <!-- First sub-framework -->
      <div class="setup-card">
        <h2>First sub-framework</h2>
        <p class="text-xs text-muted" style="margin:0 0 14px">A sub-framework is a top-level grouping of your framework — it contains its own three-level hierarchy (Tactic → Technique → Sub-technique). Most frameworks start with a single one; you can add more and rename the level terms later in Settings. <em>For example, MITRE ATT&amp;CK groups its content into "Enterprise", "Mobile", and "ICS".</em></p>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Sub-framework name</label>
            <input class="form-input" name="sf_label" value="<?= htmlspecialchars($vals['sf_label']) ?>" placeholder="e.g. Enterprise" required>
            <div class="text-xs text-muted" style="margin-top:4px">The name of the grouping itself — not a level. If your framework isn't split into groups, use something like "Main".</div>
          </div>
          <div class="form-group">
            <label class="form-label">ID prefix</label>
            <input class="form-input" name="sf_prefix" value="<?= htmlspecialchars($vals['sf_prefix']) ?>" placeholder="e.g. ENT" style="text-transform:uppercase" required>
            <div class="text-xs text-muted" style="margin-top:4px">Short code prefixed to every ID in this sub-framework (e.g. <code>ENT001</code>, <code>ENT001.001</code>).</div>
          </div>
        </div>
      </div>

      <button class="btn btn-primary" type="submit" style="width:100%;padding:12px" <?= $criticalFail ? 'disabled' : '' ?>>
        Complete setup &amp; sign in
      </button>
    </form>
  </div>
</body>
</html>
