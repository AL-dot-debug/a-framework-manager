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
 * Framework Manager — REST API
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/github.php';
require_once __DIR__ . '/includes/deepl.php';
require_once __DIR__ . '/includes/log.php';

initDataDir();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Disable output buffering for clean JSON responses
while (ob_get_level()) ob_end_clean();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

// CORS for public API endpoints
if (str_starts_with($action, 'public_')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// Parse JSON body for POST requests
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true) ?? [];
    }
}

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requireAuthApi(): array {
    $user = getCurrentUser();
    if (!$user) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    return $user;
}

/** Build per-sub-framework release stats (counts + display labels) for any profile shape. */
function buildReleaseStats(array $fw): array {
    $stats = [];
    foreach (frameworkSlugs() as $slug) {
        $conf = subframeworkConf($slug);
        $all  = extractTechniques($fw, null, $slug);
        $stats[$slug] = [
            'label'        => $conf['label'] ?? $slug,
            'tactic'       => count(extractTactics($fw, $slug)),
            'technique'    => count(array_filter($all, fn($t) => empty($t['is_subtechnique']))),
            'subtechnique' => count(array_filter($all, fn($t) => !empty($t['is_subtechnique']))),
            'level_labels' => [
                'tactic'       => $conf['levels']['tactic']['plural'] ?? 'Tactics',
                'technique'    => $conf['levels']['technique']['plural'] ?? 'Techniques',
                'subtechnique' => $conf['levels']['subtechnique']['plural'] ?? 'Sub-techniques',
            ],
        ];
    }
    return $stats;
}

/** Substitute {{profile}} placeholders in governance/doc templates from the active profile. */
function renderDocTemplate(string $text): string {
    return strtr($text, [
        '{{product_name}}'   => profileValue('product_name', 'Framework Manager'),
        '{{framework_name}}' => profileValue('framework_name', 'Framework'),
        '{{org_name}}'       => profileValue('org_name', 'The Foundation'),
        '{{org_short}}'      => profileValue('org_short', ''),
    ]);
}

function requireAdminApi(): array {
    $user = requireAuthApi();
    if ($user['role'] !== 'admin') {
        jsonResponse(['error' => 'Forbidden'], 403);
    }
    return $user;
}

function requirePublicToken(): void {
    $config = getConfig();
    $token = $config['public_api']['token'] ?? '';
    if (!$token) {
        jsonResponse(['error' => 'Public API not configured'], 503);
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m) || !hash_equals($token, $m[1])) {
        jsonResponse(['error' => 'Invalid or missing API token'], 401);
    }
}

function applyChangeToFramework(array $change, array $fw): array {
    $type = $change['type'] ?? '';
    $stixId = $change['stix_id'] ?? '';
    $after = $change['after'] ?? [];

    if ($type === 'edit') {
        foreach ($fw['objects'] as &$obj) {
            if (($obj['id'] ?? '') !== $stixId) continue;
            if (!empty($after['name'])) {
                $obj['name'] = $after['name'];
            }
            if (array_key_exists('description', $after)) {
                $obj['description'] = $after['description'];
            }
            // Update tactic associations for attack-patterns
            if (($obj['type'] ?? '') === 'attack-pattern' && isset($after['tactic_shortnames'])) {
                $kcName = frameworkToKillChain($change['framework'] ?? (frameworkSlugs()[0] ?? ''));
                $killChain = [];
                foreach ($after['tactic_shortnames'] as $shortname) {
                    $killChain[] = [
                        'kill_chain_name' => $kcName,
                        'phase_name'      => $shortname,
                    ];
                }
                $obj['kill_chain_phases'] = $killChain;
            }
            // Update platforms
            if (isset($after['platforms'])) {
                $obj['x_mitre_platforms'] = $after['platforms'];
            }
            // Update related reports in external_references
            if (array_key_exists('related_reports', $after)) {
                // Preserve the framework ID reference, replace report entries
                $preserved = [];
                foreach ($obj['external_references'] ?? [] as $ref) {
                    if (($ref['source_name'] ?? '') !== 'report') {
                        $preserved[] = $ref;
                    }
                }
                foreach ($after['related_reports'] ?? [] as $report) {
                    if (!empty($report['url'])) {
                        $preserved[] = [
                            'source_name' => 'report',
                            'url'         => $report['url'],
                            'description' => $report['excerpt'] ?? '',
                        ];
                    }
                }
                $obj['external_references'] = $preserved;
            }
            $obj['modified'] = date('c');
            break;
        }
        unset($obj);

        // Sync related-to relationships for edits
        if (array_key_exists('related_techniques', $after)) {
            $relatedEntries = $after['related_techniques'] ?? [];
            // Remove existing related-to relationships involving this technique
            $fw['objects'] = array_values(array_filter($fw['objects'], function ($obj) use ($stixId) {
                if (($obj['type'] ?? '') !== 'relationship') return true;
                if (($obj['relationship_type'] ?? '') !== 'related-to') return true;
                return ($obj['source_ref'] ?? '') !== $stixId && ($obj['target_ref'] ?? '') !== $stixId;
            }));
            // Create new related-to relationships (store once per pair, source = this technique)
            foreach ($relatedEntries as $entry) {
                $fw['objects'][] = [
                    'type'              => 'relationship',
                    'spec_version'      => '2.1',
                    'id'                => 'relationship--' . generateUUID(),
                    'created'           => date('c'),
                    'modified'          => date('c'),
                    'relationship_type' => 'related-to',
                    'description'       => $entry['description'] ?? '',
                    'source_ref'        => $stixId,
                    'target_ref'        => $entry['stix_id'],
                    'object_marking_refs' => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
                ];
            }
        }
    } elseif ($type === 'add') {
        $targetType = $change['target_type'] ?? 'technique';
        $framework = $change['framework'] ?? (frameworkSlugs()[0] ?? '');
        $kcName = frameworkToKillChain($framework);
        $sourceName = profileValue('source_name');
        $pendingIds = getPendingFrameworkIds($framework);

        if ($targetType === 'tactic') {
            // Auto-generate category/impact ID
            $frameworkId = generateNextTacticId($fw, $pendingIds, $framework);
            $newObj = [
                'type'               => 'x-mitre-tactic',
                'spec_version'       => '2.1',
                'id'                 => 'x-mitre-tactic--' . generateUUID(),
                'created'            => date('c'),
                'modified'           => date('c'),
                'name'               => $after['name'] ?? 'New Tactic',
                'description'        => $after['description'] ?? '',
                'x_mitre_shortname'  => preg_replace('/[^a-z0-9,.\-]/', '', strtolower($after['shortname'] ?? '')),
                stixProp('framework') => $framework,
                'external_references' => [
                    [
                        'source_name' => $sourceName,
                        'external_id' => $frameworkId,
                        'url'         => '',
                    ],
                ],
                'object_marking_refs' => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
            ];
            $fw['objects'][] = $newObj;
        } elseif ($targetType === 'technique') {
            // Auto-generate technique ID (always under a parent tactic)
            $parentId = $after['parent_framework_id'] ?? '';
            $frameworkId = generateNextTechniqueId($fw, $parentId, $pendingIds);
            $newStixId = 'attack-pattern--' . generateUUID();
            $extRefs = [
                [
                    'source_name' => $sourceName,
                    'external_id' => $frameworkId,
                    'url'         => '',
                ],
            ];
            // Add report references
            foreach ($after['related_reports'] ?? [] as $report) {
                if (!empty($report['url'])) {
                    $extRefs[] = [
                        'source_name' => 'report',
                        'url'         => $report['url'],
                        'description' => $report['excerpt'] ?? '',
                    ];
                }
            }
            $newObj = [
                'type'                    => 'attack-pattern',
                'id'                      => $newStixId,
                'spec_version'            => '2.1',
                'name'                    => $after['name'] ?? 'New Technique',
                'description'             => $after['description'] ?? '',
                'created'                 => date('c'),
                'modified'                => date('c'),
                stixProp('framework')      => $framework,
                'x_mitre_platforms'       => $after['platforms'] ?? [],
                'kill_chain_phases'       => array_map(fn($s) => [
                    'kill_chain_name' => $kcName,
                    'phase_name'      => $s,
                ], $after['tactic_shortnames'] ?? []),
                'external_references'     => $extRefs,
                'object_marking_refs' => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
                'x_mitre_version'     => '2.1',
            ];
            $fw['objects'][] = $newObj;

            // Create related-to relationships
            foreach ($after['related_techniques'] ?? [] as $rel) {
                if (!empty($rel['stix_id'])) {
                    $fw['objects'][] = [
                        'type'              => 'relationship',
                        'spec_version'      => '2.1',
                        'id'                => 'relationship--' . generateUUID(),
                        'created'           => date('c'),
                        'modified'          => date('c'),
                        'relationship_type' => 'related-to',
                        'description'       => $rel['description'] ?? '',
                        'source_ref'        => $newStixId,
                        'target_ref'        => $rel['stix_id'],
                        'object_marking_refs' => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
                    ];
                }
            }
        } elseif ($targetType === 'subtechnique') {
            // Auto-generate sub-technique ID under a parent technique
            $parentId = $after['parent_framework_id'] ?? '';
            $frameworkId = generateNextSubTechniqueId($fw, $parentId, $pendingIds);
            $newStixId = 'attack-pattern--' . generateUUID();

            // Resolve parent technique STIX ID from its framework_id
            $parentStixId = '';
            foreach ($fw['objects'] as $obj) {
                if (($obj['type'] ?? '') !== 'attack-pattern') continue;
                foreach ($obj['external_references'] ?? [] as $ref) {
                    if (($ref['external_id'] ?? '') === $parentId) {
                        $parentStixId = $obj['id'];
                        break 2;
                    }
                }
            }

            // Inherit tactic associations from parent technique
            $parentKillChain = [];
            foreach ($fw['objects'] as $obj) {
                if (($obj['id'] ?? '') === $parentStixId) {
                    $parentKillChain = $obj['kill_chain_phases'] ?? [];
                    break;
                }
            }

            $extRefs = [
                [
                    'source_name' => $sourceName,
                    'external_id' => $frameworkId,
                    'url'         => '',
                ],
            ];
            foreach ($after['related_reports'] ?? [] as $report) {
                if (!empty($report['url'])) {
                    $extRefs[] = [
                        'source_name' => 'report',
                        'url'         => $report['url'],
                        'description' => $report['excerpt'] ?? '',
                    ];
                }
            }

            $newObj = [
                'type'                     => 'attack-pattern',
                'id'                       => $newStixId,
                'spec_version'             => '2.1',
                'name'                     => $after['name'] ?? 'New Sub-technique',
                'description'              => $after['description'] ?? '',
                'created'                  => date('c'),
                'modified'                 => date('c'),
                stixProp('framework')       => $framework,
                'x_mitre_is_subtechnique'  => true,
                'x_mitre_platforms'        => $after['platforms'] ?? [],
                'kill_chain_phases'        => $parentKillChain,
                'external_references'      => $extRefs,
                'object_marking_refs'      => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
                'x_mitre_version'          => '2.1',
            ];
            $fw['objects'][] = $newObj;

            // Create subtechnique-of relationship
            if ($parentStixId) {
                $fw['objects'][] = [
                    'type'              => 'relationship',
                    'spec_version'      => '2.1',
                    'id'                => 'relationship--' . generateUUID(),
                    'created'           => date('c'),
                    'modified'          => date('c'),
                    'relationship_type' => 'subtechnique-of',
                    'description'       => '',
                    'source_ref'        => $newStixId,
                    'target_ref'        => $parentStixId,
                    'object_marking_refs' => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
                ];
            }

            // Create related-to relationships
            foreach ($after['related_techniques'] ?? [] as $rel) {
                if (!empty($rel['stix_id'])) {
                    $fw['objects'][] = [
                        'type'              => 'relationship',
                        'spec_version'      => '2.1',
                        'id'                => 'relationship--' . generateUUID(),
                        'created'           => date('c'),
                        'modified'          => date('c'),
                        'relationship_type' => 'related-to',
                        'description'       => $rel['description'] ?? '',
                        'source_ref'        => $newStixId,
                        'target_ref'        => $rel['stix_id'],
                        'object_marking_refs' => ['marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31'],
                    ];
                }
            }
        }
    } elseif ($type === 'delete') {
        // Mark as deprecated — never hard-delete to preserve cross-version integrity
        foreach ($fw['objects'] as &$obj) {
            if (($obj['id'] ?? '') !== $stixId) continue;
            $obj['x_mitre_deprecated'] = true;
            $obj['revoked']            = true;
            $obj['modified']           = date('c');
            if (!str_starts_with($obj['name'] ?? '', '[DEPRECATED] ')) {
                $obj['name'] = '[DEPRECATED] ' . ($obj['name'] ?? '');
            }
            $replacedBy = $after['replaced_by'] ?? null;
            if ($replacedBy) {
                $obj['x_mitre_replaced_by'] = $replacedBy;
            }
            break;
        }
        unset($obj);
    }

    return $fw;
}

// ─── Route ────────────────────────────────────────────────────────────────────

// Until the setup wizard has run there is no real admin — refuse every action
// (this also closes any default-account login before setup completes).
if (!isInstalled()) {
    jsonResponse(['error' => 'Setup not complete. Open /setup.php'], 503);
}

switch ($action) {

    // ── Auth ──────────────────────────────────────────────────────────────────

    case 'login':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        if (!$username || !$password) {
            jsonResponse(['success' => false, 'error' => 'Username and password required'], 401);
        }
        $user = login($username, $password);
        if ($user) {
            writeLog('login', ['username' => $username]);
            jsonResponse(['success' => true, 'user' => $user]);
        } else {
            writeLog('login_failed', ['username' => $username], 'warning');
            jsonResponse(['success' => false, 'error' => 'Invalid username or password'], 401);
        }

    case 'logout':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        writeLog('logout');
        logout();
        jsonResponse(['success' => true]);

    case 'get_current_user':
        $user = getCurrentUser();
        if ($user) {
            jsonResponse(['user' => $user]);
        } else {
            jsonResponse(['user' => null], 401);
        }

    // ── Framework ─────────────────────────────────────────────────────────────

    case 'get_tactics':
        requireAuthApi();
        $framework = $_GET['framework'] ?? '';
        if (!in_array($framework, frameworkSlugs(), true)) {
            jsonResponse(['error' => "framework parameter required (must be a configured sub-framework)"], 400);
        }
        $fw = getFramework();
        jsonResponse(['tactics' => extractTactics($fw, $framework)]);

    case 'get_techniques':
        requireAuthApi();
        $framework = $_GET['framework'] ?? '';
        if (!in_array($framework, frameworkSlugs(), true)) {
            jsonResponse(['error' => "framework parameter required (must be a configured sub-framework)"], 400);
        }
        $tactic = $_GET['tactic'] ?? null;
        $fw = getFramework();
        jsonResponse(['techniques' => extractTechniques($fw, $tactic ?: null, $framework)]);

    // ── Changes ───────────────────────────────────────────────────────────────

    case 'propose_change':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAuthApi();
        $required = ['type', 'target_type'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                jsonResponse(['success' => false, 'error' => "Field '$field' is required"]);
            }
        }
        if (!in_array($body['type'], ['add', 'edit', 'delete'], true)) {
            jsonResponse(['success' => false, 'error' => "Invalid type. Must be one of: add, edit, delete"]);
        }
        if (!in_array($body['target_type'], ['tactic', 'technique', 'subtechnique'], true)) {
            jsonResponse(['success' => false, 'error' => "Invalid target_type. Must be one of: tactic, technique, subtechnique"]);
        }
        $framework = $body['framework'] ?? '';
        if (!in_array($framework, frameworkSlugs(), true)) {
            jsonResponse(['success' => false, 'error' => "Invalid framework. Must be a configured sub-framework"]);
        }
        $change = [
            'type'        => $body['type'],
            'target_type' => $body['target_type'],
            'framework'   => $framework,
            'stix_id'     => $body['stix_id'] ?? '',
            'framework_id'   => $body['framework_id'] ?? '',
            'before'      => $body['before'] ?? null,
            'after'       => $body['after'] ?? null,
            'description' => $body['description'] ?? '',
            'author_id'   => $user['id'],
            'author_name' => $user['name'],
        ];
        // Reserve a preview framework ID for add proposals to prevent duplicates.
        // Wrap in withIdLock to serialize ID generation + change creation atomically.
        $changeId = withIdLock(function () use ($change, $body) {
            if (($change['type'] ?? '') === 'add') {
                $fw = getFramework();
                $framework = $change['framework'];
                $pendingIds = getPendingFrameworkIds($framework);
                $targetType = $change['target_type'] ?? 'technique';
                if ($targetType === 'tactic') {
                    $change['preview_framework_id'] = generateNextTacticId($fw, $pendingIds, $framework);
                } elseif ($targetType === 'subtechnique') {
                    $parentId = $body['after']['parent_framework_id'] ?? '';
                    $change['preview_framework_id'] = generateNextSubTechniqueId($fw, $parentId, $pendingIds);
                } else {
                    $parentId = $body['after']['parent_framework_id'] ?? '';
                    $change['preview_framework_id'] = generateNextTechniqueId($fw, $parentId, $pendingIds);
                }
            }
            return addChange($change);
        });
        writeLog('propose_change', ['change_id' => $changeId, 'type' => $body['type'], 'target_type' => $body['target_type']]);
        jsonResponse(['success' => true, 'change_id' => $changeId]);

    case 'get_changes':
        requireAuthApi();
        $data = getChangesData();
        $changes = $data['changes'] ?? [];
        $status = $_GET['status'] ?? null;
        if ($status) {
            $changes = array_values(array_filter($changes, fn($c) => ($c['status'] ?? '') === $status));
        }
        // Sort newest first
        usort($changes, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        jsonResponse(['changes' => $changes]);

    case 'approve_change':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAuthApi();
        $changeId = $body['change_id'] ?? '';
        if (!$changeId) jsonResponse(['success' => false, 'error' => 'change_id required']);

        $data = getChangesData();
        $found = null;
        foreach ($data['changes'] as $c) {
            if ($c['id'] === $changeId) { $found = $c; break; }
        }
        if (!$found) jsonResponse(['success' => false, 'error' => 'Change not found']);
        if ($found['status'] !== 'pending') jsonResponse(['success' => false, 'error' => 'Change is not pending']);
        if ($found['author_id'] === $user['id']) jsonResponse(['success' => false, 'error' => 'Cannot approve your own change']);

        // Verify target object exists for edit/delete
        $fw = getFramework();
        if (in_array($found['type'], ['edit', 'delete'], true)) {
            $targetStixId = $found['stix_id'] ?? '';
            $targetExists = false;
            foreach ($fw['objects'] ?? [] as $obj) {
                if (($obj['id'] ?? '') === $targetStixId) {
                    $targetExists = true;
                    break;
                }
            }
            if (!$targetExists) {
                jsonResponse(['success' => false, 'error' => 'Target object not found in framework']);
            }
        }

        // Atomically mark as approved first to prevent double-approval race
        $freshData = getChangesData();
        $stillPending = false;
        foreach ($freshData['changes'] as $c) {
            if ($c['id'] === $changeId && $c['status'] === 'pending') {
                $stillPending = true;
                break;
            }
        }
        if (!$stillPending) {
            jsonResponse(['success' => false, 'error' => 'Change has already been processed']);
        }

        updateChange($changeId, [
            'status'       => 'approved',
            'reviewer_id'  => $user['id'],
            'reviewer_name'=> $user['name'],
            'comment'      => $body['comment'] ?? '',
            'reviewed_at'  => date('c'),
        ]);

        // Apply to framework after status is updated
        $fw = applyChangeToFramework($found, $fw);
        saveFramework($fw);
        writeLog('approve_change', ['change_id' => $changeId]);
        jsonResponse(['success' => true]);

    case 'reject_change':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAuthApi();
        $changeId = $body['change_id'] ?? '';
        if (!$changeId) jsonResponse(['success' => false, 'error' => 'change_id required']);

        $data = getChangesData();
        $found = null;
        foreach ($data['changes'] as $c) {
            if ($c['id'] === $changeId) { $found = $c; break; }
        }
        if (!$found) jsonResponse(['success' => false, 'error' => 'Change not found']);
        if ($found['status'] !== 'pending') jsonResponse(['success' => false, 'error' => 'Change is not pending']);
        if ($found['author_id'] === $user['id']) jsonResponse(['success' => false, 'error' => 'Cannot reject your own change']);

        updateChange($changeId, [
            'status'       => 'rejected',
            'reviewer_id'  => $user['id'],
            'reviewer_name'=> $user['name'],
            'comment'      => $body['comment'] ?? '',
            'reviewed_at'  => date('c'),
        ]);
        writeLog('reject_change', ['change_id' => $changeId]);
        jsonResponse(['success' => true]);

    case 'delete_change':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAuthApi();
        $changeId = $body['change_id'] ?? '';
        if (!$changeId) jsonResponse(['success' => false, 'error' => 'change_id required']);

        $data = getChangesData();
        $found = null;
        foreach ($data['changes'] as $c) {
            if ($c['id'] === $changeId) { $found = $c; break; }
        }
        if (!$found) jsonResponse(['success' => false, 'error' => 'Change not found']);
        if ($found['status'] !== 'pending') jsonResponse(['success' => false, 'error' => 'Only pending changes can be withdrawn']);
        if ($found['author_id'] !== $user['id']) jsonResponse(['success' => false, 'error' => 'You can only withdraw your own proposals']);

        updateChange($changeId, [
            'status'       => 'withdrawn',
            'withdrawn_at' => date('c'),
            'withdrawn_by' => $user['id'],
        ]);
        writeLog('withdraw_change', ['change_id' => $changeId]);
        jsonResponse(['success' => true]);

    // ── Releases ──────────────────────────────────────────────────────────────

    case 'get_releases':
        requireAuthApi();
        $relData = getReleases();
        $releases = $relData['releases'] ?? [];
        usort($releases, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        jsonResponse(['releases' => $releases]);

    case 'create_release':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAdminApi();
        $version = trim($body['version'] ?? '');
        $name    = trim($body['name'] ?? '');
        $notes   = trim($body['notes'] ?? '');
        if (!$version) jsonResponse(['success' => false, 'error' => 'Version is required']);

        // Check for duplicate version
        $existingReleases = getReleases();
        foreach ($existingReleases['releases'] ?? [] as $existingRelease) {
            if (($existingRelease['version'] ?? '') === $version) {
                jsonResponse(['success' => false, 'error' => "A release with version '$version' already exists"]);
            }
        }

        $fw = getFramework();
        $release = [
            'id'          => generateUUID(),
            'version'     => $version,
            'name'        => $name ?: "Release $version",
            'notes'       => $notes,
            'created_at'  => date('c'),
            'created_by'  => $user['name'],
            'stats'       => buildReleaseStats($fw),
        ];

        // Snapshot the framework
        $snapshotFile = RELEASES_DIR . '/v' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $version) . '.json';
        writeJSON($snapshotFile, $fw);
        $release['snapshot_file'] = basename($snapshotFile);

        addRelease($release);
        writeLog('create_release', ['version' => $version]);
        jsonResponse(['success' => true, 'release' => $release]);

    // ── Users (admin) ─────────────────────────────────────────────────────────

    case 'get_users':
        requireAdminApi();
        $users = getUsers();
        $safe = array_map(function($u) {
            unset($u['password']);
            return $u;
        }, $users);
        jsonResponse(['users' => array_values($safe)]);

    case 'create_user':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        $username = trim($body['username'] ?? '');
        $name     = trim($body['name'] ?? '');
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $role     = $body['role'] ?? 'user';
        if (!$username || !$name || !$password) {
            jsonResponse(['success' => false, 'error' => 'Username, name and password are required']);
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid role']);
        }
        $users = getUsers();
        foreach ($users as $u) {
            if ($u['username'] === $username) {
                jsonResponse(['success' => false, 'error' => 'Username already exists']);
            }
        }
        $tscRole = $body['tsc_role'] ?? '';
        if (!in_array($tscRole, ['', 'president', 'vice-president', 'member'], true)) $tscRole = '';
        $newUser = [
            'id'         => generateUUID(),
            'username'   => $username,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
            'name'       => $name,
            'email'      => $email,
            'role'       => $role,
            'tsc_role'   => $tscRole,
            'created_at' => date('c'),
        ];
        $users[] = $newUser;
        saveUsers($users);
        writeLog('create_user', ['username' => $username, 'role' => $role]);
        unset($newUser['password']);
        jsonResponse(['success' => true, 'user' => $newUser]);

    case 'update_user':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $currentUser = requireAdminApi();
        $id = $body['id'] ?? '';
        if (!$id) jsonResponse(['success' => false, 'error' => 'User ID required']);
        $users = getUsers();
        $found = false;
        foreach ($users as &$u) {
            if ($u['id'] !== $id) continue;
            $found = true;
            // Prevent admin self-demotion
            if ($u['id'] === $currentUser['id'] && !empty($body['role']) && $body['role'] !== 'admin' && $u['role'] === 'admin') {
                jsonResponse(['success' => false, 'error' => 'Cannot demote your own admin account']);
            }
            if (array_key_exists('name', $body))  $u['name']  = trim($body['name'] ?? '');
            if (array_key_exists('email', $body)) $u['email'] = trim($body['email'] ?? '');
            if (!empty($body['role']) && in_array($body['role'], ['admin', 'user'], true)) {
                $u['role'] = $body['role'];
            }
            if (!empty($body['password'])) {
                $u['password'] = password_hash($body['password'], PASSWORD_DEFAULT);
            }
            if (array_key_exists('tsc_role', $body)) {
                $allowed = ['', 'president', 'vice-president', 'member'];
                $u['tsc_role'] = in_array($body['tsc_role'], $allowed, true) ? $body['tsc_role'] : '';
            }
            break;
        }
        unset($u);
        if (!$found) jsonResponse(['success' => false, 'error' => 'User not found']);
        saveUsers($users);
        writeLog('update_user', ['user_id' => $id]);
        jsonResponse(['success' => true]);

    case 'delete_user':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $currentUser = requireAdminApi();
        $id = $body['id'] ?? '';
        if (!$id) jsonResponse(['success' => false, 'error' => 'User ID required']);
        if ($id === $currentUser['id']) {
            jsonResponse(['success' => false, 'error' => 'Cannot delete your own account']);
        }
        $users = getUsers();
        $filtered = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
        if (count($filtered) === count($users)) {
            jsonResponse(['success' => false, 'error' => 'User not found']);
        }
        saveUsers($filtered);
        writeLog('delete_user', ['user_id' => $id]);
        jsonResponse(['success' => true]);

    case 'get_next_id':
        requireAuthApi();
        $idType   = $_GET['id_type'] ?? 'technique'; // tactic | technique | subtechnique
        $parentId = $_GET['parent_id'] ?? '';
        $framework = $_GET['framework'] ?? '';
        if (!in_array($framework, frameworkSlugs(), true)) {
            jsonResponse(['error' => "framework parameter required (must be a configured sub-framework)"], 400);
        }
        $fw = getFramework();
        $pendingIds = getPendingFrameworkIds($framework);
        if ($idType === 'tactic') {
            $nextId = generateNextTacticId($fw, $pendingIds, $framework);
        } elseif ($idType === 'subtechnique') {
            $nextId = generateNextSubTechniqueId($fw, $parentId, $pendingIds);
        } else {
            $nextId = generateNextTechniqueId($fw, $parentId, $pendingIds);
        }
        jsonResponse(['id' => $nextId]);

    // ── GitHub / Publish ─────────────────────────────────────────────────────

    case 'get_charter':
        requireAuthApi();
        // Admin edits live in data/ (writable); repo file is the default template.
        $src = file_exists(DATA_DIR . '/charter.md') ? DATA_DIR . '/charter.md' : __DIR__ . '/TSC_Commitee_Charter.md';
        $raw = file_exists($src) ? file_get_contents($src) : '';
        jsonResponse(['content' => renderDocTemplate($raw), 'raw' => $raw]);

    case 'save_charter':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        if (file_put_contents(DATA_DIR . '/charter.md', (string)($body['content'] ?? '')) === false) {
            jsonResponse(['success' => false, 'error' => 'Could not save charter']);
        }
        writeLog('save_charter');
        jsonResponse(['success' => true]);

    case 'get_code_of_conduct':
        requireAuthApi();
        $src = file_exists(DATA_DIR . '/code_of_conduct.md') ? DATA_DIR . '/code_of_conduct.md' : __DIR__ . '/Code_of_Conduct.md';
        $raw = file_exists($src) ? file_get_contents($src) : '';
        jsonResponse(['content' => renderDocTemplate($raw), 'raw' => $raw]);

    case 'save_code_of_conduct':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        if (file_put_contents(DATA_DIR . '/code_of_conduct.md', (string)($body['content'] ?? '')) === false) {
            jsonResponse(['success' => false, 'error' => 'Could not save code of conduct']);
        }
        writeLog('save_code_of_conduct');
        jsonResponse(['success' => true]);

    case 'get_committee_members':
        requireAuthApi();
        $users = getUsers();
        $members = [];
        foreach ($users as $u) {
            $tscRole = $u['tsc_role'] ?? '';
            if (!$tscRole) continue;
            $members[] = [
                'id'       => $u['id'],
                'name'     => $u['name'],
                'email'    => $u['email'],
                'role'     => $tscRole,
                'title'    => match($tscRole) { 'president' => 'President', 'vice-president' => 'Vice-President', default => 'Member' },
                'since'    => $u['created_at'] ?? '',
            ];
        }
        // Sort: president first, then VPs, then members
        $order = ['president' => 0, 'vice-president' => 1, 'member' => 2];
        usort($members, fn($a, $b) => ($order[$a['role']] ?? 3) <=> ($order[$b['role']] ?? 3));
        jsonResponse(['members' => $members]);

    // ── GitHub / Publish ─────────────────────────────────────────────────────

    case 'get_profile':
        requireAdminApi();
        $available = [];
        foreach (glob(__DIR__ . '/profiles/*.json') as $pf) {
            $base = basename($pf, '.json');
            if (str_ends_with($base, '-bundle')) continue; // skip STIX data bundles
            $available[] = $base;
        }
        jsonResponse([
            'profile'             => getProfile(),
            'available'           => $available,
            'instance_source_url' => getConfig()['instance_source_url'] ?? '',
            'upstream_source_url' => APP_SOURCE_URL,
        ]);

    case 'save_instance_source_url':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        $url = trim($body['url'] ?? '');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            jsonResponse(['success' => false, 'error' => 'Enter a valid URL (starting with http:// or https://) or leave it blank']);
        }
        saveConfig(['instance_source_url' => $url]);
        writeLog('save_instance_source_url');
        jsonResponse(['success' => true]);

    case 'save_profile':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        $profile = $body['profile'] ?? null;
        // Allow importing a named profile file from profiles/
        if (!$profile && !empty($body['import'])) {
            $name = preg_replace('/[^a-z0-9_-]/i', '', $body['import']);
            $pf = __DIR__ . '/profiles/' . $name . '.json';
            if (!is_file($pf)) jsonResponse(['success' => false, 'error' => 'Profile not found']);
            $profile = json_decode(file_get_contents($pf), true);
        }
        if (!is_array($profile)) {
            jsonResponse(['success' => false, 'error' => 'A profile object (or import name) is required']);
        }
        // Validation: at least one sub-framework; each needs a kill_chain_name
        // and id_prefix; slugs, prefixes and kill-chain names must be unique.
        $subs = $profile['subframeworks'] ?? [];
        if (!is_array($subs) || count($subs) < 1) {
            jsonResponse(['success' => false, 'error' => 'At least one sub-framework is required']);
        }
        $seenPrefix = [];
        $seenKc = [];
        foreach ($subs as $slug => $conf) {
            if (!is_string($slug) || !preg_match('/^[a-z0-9_]+$/', $slug)) {
                jsonResponse(['success' => false, 'error' => "Invalid sub-framework key '$slug' (use lowercase letters, digits, underscore)"]);
            }
            $kc = trim($conf['kill_chain_name'] ?? '');
            $pfx = trim($conf['id_prefix'] ?? '');
            if ($kc === '' || $pfx === '') {
                jsonResponse(['success' => false, 'error' => "Sub-framework '$slug' needs an ID prefix and a kill-chain name"]);
            }
            if (isset($seenPrefix[strtoupper($pfx)])) {
                jsonResponse(['success' => false, 'error' => "Duplicate ID prefix '$pfx'"]);
            }
            if (isset($seenKc[$kc])) {
                jsonResponse(['success' => false, 'error' => "Duplicate kill-chain name '$kc'"]);
            }
            $seenPrefix[strtoupper($pfx)] = true;
            $seenKc[$kc] = true;
        }
        // Removing a sub-framework permanently deletes its stored data; changing
        // a kept sub-framework's kill-chain name / STIX prefix re-tags its data.
        $deletedObjects  = deleteRemovedSubframeworkData($profile);
        $migratedObjects = migrateProfileStructuralChanges($profile);
        saveProfile($profile);
        writeLog('save_profile', [
            'framework_name'   => $profile['framework_name'] ?? '',
            'deleted_objects'  => $deletedObjects,
            'migrated_objects' => $migratedObjects,
        ]);
        jsonResponse(['success' => true, 'deleted_objects' => $deletedObjects, 'migrated_objects' => $migratedObjects]);

    case 'get_github_config':
        requireAdminApi();
        $config = getConfig();
        $gh = $config['github'];
        $tr = $config['translations'] ?? [];
        $pa = $config['public_api'] ?? [];
        jsonResponse([
            'config' => [
                'app_id'                => $gh['app_id'] ?? '',
                'installation_id'       => $gh['installation_id'] ?? '',
                'owner'                 => $gh['owner'] ?? '',
                'repo'                  => $gh['repo'] ?? '',
                'base_branch'           => $gh['base_branch'] ?? 'main',
                'dev_branch'            => $gh['dev_branch'] ?? 'dev',
                'key_exists'            => file_exists($gh['private_key_path'] ?? ''),
                'deepl_key_exists'      => !empty($tr['deepl_api_key']),
                'translation_languages' => $tr['languages'] ?? '',
                'public_api_token'      => $pa['token'] ?? '',
            ],
        ]);

    case 'save_github_config':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();

        // Preserve existing DeepL key if not provided in this request
        $existingConfig = getConfig();
        $existingDeeplKey = $existingConfig['translations']['deepl_api_key'] ?? '';

        $deeplKey = trim($body['deepl_api_key'] ?? '');
        $values = [
            'github' => [
                'app_id'          => trim($body['app_id'] ?? ''),
                'installation_id' => trim($body['installation_id'] ?? ''),
                'owner'           => trim($body['owner'] ?? ''),
                'repo'            => trim($body['repo'] ?? ''),
                'base_branch'     => trim($body['base_branch'] ?? '') ?: 'main',
                'dev_branch'      => trim($body['dev_branch'] ?? '') ?: 'dev',
            ],
            'translations' => [
                'deepl_api_key' => $deeplKey ?: $existingDeeplKey,
                'languages'     => trim($body['translation_languages'] ?? ''),
            ],
            'public_api' => [
                'token' => trim($body['public_api_token'] ?? '') ?: ($existingConfig['public_api']['token'] ?? ''),
            ],
        ];

        saveConfig($values);

        // Write PEM file if provided
        $privateKey = $body['private_key'] ?? '';
        if ($privateKey) {
            $config = getConfig();
            $keyPath = $config['github']['private_key_path'];
            file_put_contents($keyPath, $privateKey);
            chmod($keyPath, 0600);
        }

        writeLog('save_config');
        jsonResponse(['success' => true]);

    case 'delete_config':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        $section = $body['section'] ?? '';
        if (!in_array($section, ['github', 'translations', 'public_api'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid section']);
        }
        // For GitHub, also remove the stored PEM key file.
        if ($section === 'github') {
            $keyPath = getConfig()['github']['private_key_path'] ?? '';
            if ($keyPath && file_exists($keyPath)) @unlink($keyPath);
        }
        // Remove the section's runtime overrides; getConfig() falls back to the
        // (empty) config.php defaults, so the section is effectively cleared.
        $raw = readJSON(DATA_DIR . '/config.json');
        if (!is_array($raw)) $raw = [];
        unset($raw[$section]);
        writeJSON(DATA_DIR . '/config.json', $raw);
        writeLog('delete_config', ['section' => $section]);
        jsonResponse(['success' => true]);

    case 'test_github_connection':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();

        try {
            $token = getGitHubToken();
            $config = getConfig();
            $gh = $config['github'];
            $repoInfo = githubApi('GET', '/repos/' . $gh['owner'] . '/' . $gh['repo'], null, $token);
            jsonResponse([
                'success'   => true,
                'repo_name' => $repoInfo['full_name'] ?? '',
                'repo_url'  => $repoInfo['html_url'] ?? '',
            ]);
        } catch (RuntimeException $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }

    case 'test_deepl_connection':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        // Accept key from request body (unsaved form) or fall back to saved config
        $deeplKey = trim($body['deepl_api_key'] ?? '');
        if (!$deeplKey) {
            $config = getConfig();
            $deeplKey = $config['translations']['deepl_api_key'] ?? '';
        }
        if (!$deeplKey) jsonResponse(['success' => false, 'error' => 'DeepL API key not configured']);
        try {
            $translated = deeplTranslate('Hello', 'FR', $deeplKey);
            jsonResponse(['success' => true, 'sample' => $translated]);
        } catch (RuntimeException $e) {
            jsonResponse(['success' => false, 'error' => $e->getMessage()]);
        }

    case 'get_publish_status':
        requireAdminApi();
        $unpublished = getUnpublishedChanges();
        $publishData = getPublishData();
        $config = getConfig();
        $gh = $config['github'];
        $configured = !empty($gh['app_id']) && !empty($gh['installation_id'])
            && !empty($gh['owner']) && !empty($gh['repo'])
            && file_exists($gh['private_key_path'] ?? '');
        jsonResponse([
            'unpublished_count' => count($unpublished),
            'last_published_at' => $publishData['last_published_at'],
            'github_configured' => $configured,
        ]);

    case 'publish':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAdminApi();
        $version = trim($body['version'] ?? '');
        $name    = trim($body['name'] ?? '');
        $notes   = trim($body['notes'] ?? '');
        if (!$version) jsonResponse(['success' => false, 'error' => 'Version is required']);

        // Check for duplicate version
        $existingReleases = getReleases();
        foreach ($existingReleases['releases'] ?? [] as $existingRelease) {
            if (($existingRelease['version'] ?? '') === $version) {
                jsonResponse(['success' => false, 'error' => "A release with version '$version' already exists"]);
            }
        }

        // Switch to SSE streaming
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        if (ob_get_level()) ob_end_flush();

        $sendEvent = function(string $event, array $data) {
            echo "event: $event\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        };

        try {
            // 1. Load framework
            $sendEvent('step', ['id' => 'yaml', 'status' => 'active']);
            $fw = getFramework();
            $sendEvent('step', ['id' => 'yaml', 'status' => 'done']);

            // 2. Get unpublished changes and generate changelog
            $sendEvent('step', ['id' => 'changelog', 'status' => 'active']);
            $unpublished = getUnpublishedChanges();
            $changelog = generateChangelog($unpublished, $version);
            $sendEvent('step', ['id' => 'changelog', 'status' => 'done']);

            // 3. Publish to GitHub (with progress callback)
            $sendEvent('step', ['id' => 'documentation', 'status' => 'active']);
            $commitMessage = "Release v$version";
            if ($notes) $commitMessage .= "\n\n$notes";

            $progressFn = function(string $step) use ($sendEvent) {
                static $prev = null;
                if ($prev && $prev !== $step) {
                    $sendEvent('step', ['id' => $prev, 'status' => 'done']);
                }
                $sendEvent('step', ['id' => $step, 'status' => 'active']);
                $prev = $step;
            };

            $result = publishToGitHub($changelog, $version, $commitMessage, $fw, $progressFn);
            $sendEvent('step', ['id' => 'push', 'status' => 'done']);

            // 4. Mark changes as published
            $sendEvent('step', ['id' => 'finalize', 'status' => 'active']);
            $changeIds = array_map(fn($c) => $c['id'], $unpublished);
            if ($changeIds) {
                markChangesPublished($changeIds);
            }

            // 5. Record publication
            addPublication([
                'id'             => generateUUID(),
                'version'        => $version,
                'name'           => $name ?: "Release v$version",
                'notes'          => $notes,
                'published_at'   => date('c'),
                'published_by'   => $user['name'],
                'pr_url'         => $result['pr_url'],
                'pr_number'      => $result['pr_number'],
                'commit_sha'     => $result['commit_sha'],
                'changes_count'  => count($unpublished),
            ]);

            // 6. Also create a local release
            $release = [
                'id'          => generateUUID(),
                'version'     => $version,
                'name'        => $name ?: "Release v$version",
                'notes'       => $notes,
                'created_at'  => date('c'),
                'created_by'  => $user['name'],
                'stats'       => buildReleaseStats($fw),
            ];

            $snapshotFile = RELEASES_DIR . '/v' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $version) . '.json';
            writeJSON($snapshotFile, $fw);
            $release['snapshot_file'] = basename($snapshotFile);
            addRelease($release);
            writeLog('publish', ['version' => $version, 'pr_url' => $result['pr_url']]);
            $sendEvent('step', ['id' => 'finalize', 'status' => 'done']);

            $sendEvent('done', [
                'success'   => true,
                'pr_url'    => $result['pr_url'],
                'pr_number' => $result['pr_number'],
            ]);

        } catch (RuntimeException $e) {
            $sendEvent('error', ['error' => $e->getMessage()]);
        }
        exit;

    // ── Logs (admin) ────────────────────────────────────────────────────────

    case 'get_logs':
        requireAdminApi();
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $userFilter = $_GET['user_id'] ?? null;
        $levelFilter = $_GET['level'] ?? null;
        $actionFilter = $_GET['action_filter'] ?? null;
        $result = getLogs($limit, $offset, $userFilter ?: null, $levelFilter ?: null, $actionFilter ?: null);
        jsonResponse($result);

    case 'clear_logs':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        clearLogs();
        writeLog('clear_logs', [], 'info');
        jsonResponse(['success' => true]);

    // ── Public API (token-authenticated) ─────────────────────────────────────

    case 'public_submit_proposal':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requirePublicToken();
        // Validate required fields
        $name = trim($body['name'] ?? '');
        $email = trim($body['email'] ?? '');
        $type = trim($body['type'] ?? '');
        $title = trim($body['title'] ?? '');
        $description = trim($body['description'] ?? '');
        if (!$name || !$email || !$type || !$title || !$description) {
            jsonResponse(['success' => false, 'error' => 'Fields name, email, type, title, and description are required'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'error' => 'Invalid email address'], 400);
        }
        $allowedTypes = ['new-technique', 'modify', 'deprecate', 'structural', 'correction', 'other'];
        if (!in_array($type, $allowedTypes, true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid change type'], 400);
        }
        $submission = [
            'name'         => $name,
            'email'        => $email,
            'organisation' => trim($body['organisation'] ?? ''),
            'type'         => $type,
            'title'        => $title,
            'description'  => $description,
            'references'   => trim($body['references'] ?? ''),
            'ip'           => _getClientIp(),
        ];
        $subId = addSubmission($submission);
        writeLog('public_submission', ['submission_id' => $subId, 'title' => $title]);
        jsonResponse(['success' => true, 'submission_id' => $subId]);

    case 'public_get_members':
        requirePublicToken();
        $users = getUsers();
        $members = [];
        foreach ($users as $u) {
            $tscRole = $u['tsc_role'] ?? '';
            if (!$tscRole) continue;
            $members[] = [
                'name'  => $u['name'],
                'role'  => match($tscRole) { 'president' => 'President', 'vice-president' => 'Vice-President', default => 'Member' },
            ];
        }
        $order = ['President' => 0, 'Vice-President' => 1, 'Member' => 2];
        usort($members, fn($a, $b) => ($order[$a['role']] ?? 3) <=> ($order[$b['role']] ?? 3));
        jsonResponse(['members' => $members]);

    // ── Submissions (admin) ──────────────────────────────────────────────────

    case 'get_submissions':
        requireAdminApi();
        $data = getSubmissions();
        $submissions = $data['submissions'] ?? [];
        $status = $_GET['status'] ?? null;
        if ($status) {
            $submissions = array_values(array_filter($submissions, fn($s) => ($s['status'] ?? '') === $status));
        }
        usort($submissions, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
        jsonResponse(['submissions' => $submissions]);

    case 'update_submission':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        $user = requireAdminApi();
        $subId = $body['id'] ?? '';
        $status = $body['status'] ?? '';
        if (!$subId || !$status) jsonResponse(['success' => false, 'error' => 'id and status required']);
        if (!in_array($status, ['new', 'reviewing', 'accepted', 'rejected', 'archived'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid status']);
        }
        $updates = [
            'status'      => $status,
            'reviewed_by' => $user['name'],
            'reviewed_at' => date('c'),
        ];
        if (isset($body['notes'])) {
            $updates['notes'] = trim($body['notes']);
        }
        $ok = updateSubmission($subId, $updates);
        if (!$ok) jsonResponse(['success' => false, 'error' => 'Submission not found']);
        writeLog('update_submission', ['submission_id' => $subId, 'status' => $status]);
        jsonResponse(['success' => true]);

    case 'delete_submission':
        if ($method !== 'POST') jsonResponse(['error' => 'Method not allowed'], 405);
        requireAdminApi();
        $subId = $body['id'] ?? '';
        if (!$subId) jsonResponse(['success' => false, 'error' => 'id required']);
        modifyJSON(SUBMISSIONS_FILE, ['submissions' => []], function ($data) use ($subId) {
            $data['submissions'] = array_values(array_filter(
                $data['submissions'] ?? [],
                fn($s) => ($s['id'] ?? '') !== $subId
            ));
            return $data;
        });
        writeLog('delete_submission', ['submission_id' => $subId]);
        jsonResponse(['success' => true]);

    default:
        jsonResponse(['error' => 'Unknown action'], 404);
}
