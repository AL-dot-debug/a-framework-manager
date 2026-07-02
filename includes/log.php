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
 * Framework Manager — Activity Logging
 */

define('LOGS_FILE', DATA_DIR . '/logs.json');

function writeLog(string $action, array $details = [], ?string $level = 'info'): void {
    $user = getCurrentUser();

    $entry = [
        'id'        => generateUUID(),
        'timestamp' => date('c'),
        'level'     => $level ?? 'info',
        'action'    => $action,
        'user_id'   => $user['id'] ?? null,
        'user_name' => $user['name'] ?? 'anonymous',
        'ip'        => _getClientIp(),
        'details'   => $details,
    ];

    modifyJSON(LOGS_FILE, ['logs' => []], function (array $data) use ($entry) {
        if (!isset($data['logs']) || !is_array($data['logs'])) {
            $data['logs'] = [];
        }
        $data['logs'][] = $entry;

        // Trim to max 5000 entries (drop oldest)
        if (count($data['logs']) > 5000) {
            $data['logs'] = array_slice($data['logs'], -5000);
        }

        return $data;
    });
}

function getLogs(?int $limit = 100, ?int $offset = 0, ?string $userFilter = null, ?string $levelFilter = null, ?string $actionFilter = null): array {
    $data = readJSON(LOGS_FILE);
    $logs = (is_array($data) && isset($data['logs'])) ? $data['logs'] : [];

    // Apply filters
    if ($userFilter !== null) {
        $logs = array_filter($logs, fn($e) => ($e['user_id'] ?? '') === $userFilter);
    }
    if ($levelFilter !== null) {
        $logs = array_filter($logs, fn($e) => ($e['level'] ?? '') === $levelFilter);
    }
    if ($actionFilter !== null) {
        $logs = array_filter($logs, fn($e) => stripos($e['action'] ?? '', $actionFilter) !== false);
    }

    // Sort newest first
    usort($logs, function ($a, $b) {
        return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
    });

    $total = count($logs);

    // Paginate
    $logs = array_slice($logs, $offset ?? 0, $limit ?? 100);

    return ['logs' => $logs, 'total' => $total];
}

function clearLogs(): void {
    writeJSON(LOGS_FILE, ['logs' => []]);
}
