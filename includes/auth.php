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
 * Framework Manager — Auth Helpers
 */

require_once __DIR__ . '/data.php';

/** Detect whether the current request arrived over HTTPS (directly or via proxy). */
function _isSecure(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return false;
}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => _isSecure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function requireAuth(): void {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function getCurrentUser(): ?array {
    static $cached = false;
    static $cachedResult = null;

    if ($cached) {
        return $cachedResult;
    }

    startSession();
    if (empty($_SESSION['user_id'])) {
        $cached = true;
        $cachedResult = null;
        return null;
    }
    $users = getUsers();
    foreach ($users as $user) {
        if ($user['id'] === $_SESSION['user_id']) {
            $safe = $user;
            unset($safe['password']);
            $cached = true;
            $cachedResult = $safe;
            return $safe;
        }
    }
    $cached = true;
    $cachedResult = null;
    return null;
}

function isAdmin(): bool {
    $user = getCurrentUser();
    return $user && ($user['role'] ?? '') === 'admin';
}

// ── Brute-force rate limiting ────────────────────────────────────────

define('LOGIN_ATTEMPTS_FILE', __DIR__ . '/../data/.login_attempts.json');
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_WINDOW_SECONDS', 900); // 15 minutes

function _getClientIp(): string {
    // Trust X-Forwarded-For only for the first hop when behind a reverse proxy.
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function _readAttempts(): array {
    $file = LOGIN_ATTEMPTS_FILE;
    if (!file_exists($file)) {
        return [];
    }
    $data = readJSON($file);
    return is_array($data) ? $data : [];
}

function _writeAttempts(array $data): void {
    writeJSON(LOGIN_ATTEMPTS_FILE, $data);
}

/** Purge entries older than the rate-limit window. */
function _cleanupAttempts(array &$data): void {
    $cutoff = time() - LOGIN_WINDOW_SECONDS;
    foreach ($data as $ip => $entry) {
        // Remove timestamps older than the window.
        $entry['times'] = array_values(array_filter(
            $entry['times'] ?? [],
            fn(int $t) => $t > $cutoff
        ));
        if (empty($entry['times'])) {
            unset($data[$ip]);
        } else {
            $data[$ip] = $entry;
        }
    }
}

/** Returns true if the IP is currently blocked. */
function _isRateLimited(): bool {
    $ip = _getClientIp();
    $data = _readAttempts();
    _cleanupAttempts($data);

    $entry = $data[$ip] ?? null;
    if ($entry && count($entry['times'] ?? []) >= LOGIN_MAX_ATTEMPTS) {
        return true;
    }
    return false;
}

/** Record a failed login attempt for the current IP. */
function _recordFailedAttempt(): void {
    $ip = _getClientIp();
    $data = _readAttempts();
    _cleanupAttempts($data);

    if (!isset($data[$ip])) {
        $data[$ip] = ['times' => []];
    }
    $data[$ip]['times'][] = time();

    _writeAttempts($data);
}

/** Clear attempts for the current IP after successful login. */
function _clearAttempts(): void {
    $ip = _getClientIp();
    $data = _readAttempts();
    unset($data[$ip]);
    _writeAttempts($data);
}

// ── Login / Logout ───────────────────────────────────────────────────

function login(string $username, string $password): ?array {
    // Brute-force protection: block after too many failed attempts.
    if (_isRateLimited()) {
        return null;
    }

    // Dummy bcrypt hash used to prevent timing oracle on missing usernames.
    $dummyHash = '$2y$10$dummyhashvaluetopreventtimingleak';

    $users = getUsers();
    $foundUser = null;
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            $foundUser = $user;
            break;
        }
    }

    if ($foundUser === null) {
        // No such user — run password_verify against the dummy hash so the
        // response time is indistinguishable from a real password check.
        password_verify($password, $dummyHash);
        _recordFailedAttempt();
        return null;
    }

    if (!password_verify($password, $foundUser['password'])) {
        _recordFailedAttempt();
        return null;
    }

    // Successful login.
    _clearAttempts();
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $foundUser['id'];
    $safe = $foundUser;
    unset($safe['password']);
    return $safe;
}

function logout(): void {
    startSession();
    $cookieName = session_name();
    $_SESSION = [];
    session_destroy();
    // Clear the session cookie in the browser.
    setcookie($cookieName, '', time() - 3600, '/');
}
