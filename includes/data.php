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
 * Framework Manager — Data Helpers
 */

define('DATA_DIR', __DIR__ . '/../data');
define('SEED_SOURCE', __DIR__ . '/../seed.json');

// AGPL section 13: the running instance must offer its source to network users.
// This link is hardcoded (not editable in the admin UI) so it can never be
// removed. If you modify and redistribute, point it at your own source.
define('APP_SOURCE_URL', 'https://intheopen.eu');
define('USERS_FILE', DATA_DIR . '/users.json');
define('FRAMEWORK_FILE', DATA_DIR . '/framework.json');
define('CHANGES_FILE', DATA_DIR . '/changes.json');
define('RELEASES_DIR', DATA_DIR . '/releases');
define('RELEASES_INDEX', RELEASES_DIR . '/index.json');
define('PUBLISH_FILE', DATA_DIR . '/publish.json');
define('TRANSLATION_CACHE_FILE', DATA_DIR . '/translation_cache.json');
define('SUBMISSIONS_FILE', DATA_DIR . '/submissions.json');
define('CONFIG_FILE', __DIR__ . '/../config.php');
define('INSTALL_MARKER', DATA_DIR . '/.installed');

/** True once the setup wizard has completed. */
function isInstalled(): bool {
    return file_exists(INSTALL_MARKER);
}

/** Mark the instance as installed (records the timestamp). */
function setInstalled(): void {
    file_put_contents(INSTALL_MARKER, date('c') . "\n");
}

/** Write an Apache .htaccess denying all web access to the data directory. */
function ensureDataProtection(): void {
    $ht = DATA_DIR . '/.htaccess';
    if (file_exists($ht)) return;
    $rules = "# Auto-generated — deny all web access to the data directory (contains\n"
           . "# password hashes, API keys and the GitHub App private key).\n"
           . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
           . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    @file_put_contents($ht, $rules);
}

function initDataDir(): void {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0775, true);
    }
    if (!is_dir(RELEASES_DIR)) {
        mkdir(RELEASES_DIR, 0775, true);
    }

    // Protect data/ from direct web access as early as possible.
    ensureDataProtection();

    // Initialize users.json with default users
    if (!file_exists(USERS_FILE)) {
        $defaultUsers = [
            [
                'id'         => generateUUID(),
                'username'   => 'admin',
                'password'   => password_hash('admin123', PASSWORD_DEFAULT),
                'name'       => 'Administrator',
                'email'      => 'admin@framework.local',
                'role'       => 'admin',
                'created_at' => date('c'),
            ],
            [
                'id'         => generateUUID(),
                'username'   => 'editor',
                'password'   => password_hash('editor123', PASSWORD_DEFAULT),
                'name'       => 'Editor',
                'email'      => 'editor@framework.local',
                'role'       => 'user',
                'created_at' => date('c'),
            ],
        ];
        writeJSON(USERS_FILE, $defaultUsers);
    }

    // Copy the seed bundle to data/framework.json on first run
    if (!file_exists(FRAMEWORK_FILE)) {
        if (file_exists(SEED_SOURCE)) {
            copy(SEED_SOURCE, FRAMEWORK_FILE);
        } else {
            writeJSON(FRAMEWORK_FILE, ['type' => 'bundle', 'id' => 'bundle--' . generateUUID(), 'objects' => []]);
        }
        // Ensure the framework-membership extension-definition exists in the bundle
        ensureExtensionDefinition();
    }

    // Initialize changes.json
    if (!file_exists(CHANGES_FILE)) {
        writeJSON(CHANGES_FILE, ['changes' => []]);
    }

    // Initialize releases index
    if (!file_exists(RELEASES_INDEX)) {
        writeJSON(RELEASES_INDEX, ['releases' => []]);
    }

    // Initialize publish.json
    if (!file_exists(PUBLISH_FILE)) {
        writeJSON(PUBLISH_FILE, ['publications' => [], 'last_published_at' => null]);
    }

    // Initialize translation cache
    if (!file_exists(TRANSLATION_CACHE_FILE)) {
        writeJSON(TRANSLATION_CACHE_FILE, []);
    }

    // Initialize submissions
    if (!file_exists(SUBMISSIONS_FILE)) {
        writeJSON(SUBMISSIONS_FILE, ['submissions' => []]);
    }
}

/**
 * Ensure the STIX 2.1 extension-definition for the framework-membership
 * property exists in the bundle. All framework-specific values come from the
 * active profile.
 */
function ensureExtensionDefinition(): void {
    $extDefId  = profileValue('extension_definition_id');
    $propFw    = stixProp('framework');
    $propVer   = stixProp('version');
    $fwName    = profileValue('framework_name', 'Framework');
    $fw = json_decode(file_get_contents(FRAMEWORK_FILE), true);
    if (!$fw || !isset($fw['objects'])) return;
    foreach ($fw['objects'] as $obj) {
        if (($obj['id'] ?? '') === $extDefId) return; // already present
    }
    $fw['objects'][] = [
        'type'            => 'extension-definition',
        'spec_version'    => '2.1',
        'id'              => $extDefId,
        'created'         => '2026-03-30T00:00:00.000Z',
        'modified'        => '2026-03-30T00:00:00.000Z',
        'name'            => profileValue('extension_definition_name', 'Framework Membership'),
        'description'     => "Adds $propFw to indicate which $fwName sub-framework an object belongs to, and $propVer on grouping objects to record the framework version.",
        'schema'          => rtrim(profileValue('base_url', ''), '/') . '/stix/' . $extDefId . '.json',
        'version'         => '1.0',
        'extension_types' => ['property-extension'],
        'created_by_ref'  => profileValue('identity_ref'),
    ];
    file_put_contents(FRAMEWORK_FILE, json_encode($fw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function readJSON(string $file): mixed {
    if (!file_exists($file)) {
        return null;
    }
    $fp = fopen($file, 'r');
    if (!$fp) return null;
    if (!flock($fp, LOCK_SH)) {
        error_log("readJSON: failed to acquire shared lock on $file");
    }
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return json_decode($content, true);
}

function writeJSON(string $file, mixed $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmpFile = $file . '.tmp';
    $fp = fopen($tmpFile, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) {
        error_log("writeJSON: failed to acquire exclusive lock on $tmpFile");
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return rename($tmpFile, $file);
}

/**
 * Atomically read-modify-write a JSON file. The callback receives the current
 * data and must return the new data to write. An exclusive lock is held for
 * the entire duration so no TOCTOU race is possible.
 *
 * @param string   $file     Path to the JSON file
 * @param mixed    $default  Default value if file doesn't exist or is empty
 * @param callable $callback fn(mixed $data): mixed — receives current data, returns updated data
 * @return mixed   The value returned by $callback (i.e. the new data written)
 */
function modifyJSON(string $file, mixed $default, callable $callback): mixed {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    // Open (or create) the file for read+write
    $fp = fopen($file, 'c+');
    if (!$fp) {
        throw new \RuntimeException("modifyJSON: cannot open $file");
    }
    if (!flock($fp, LOCK_EX)) {
        error_log("modifyJSON: failed to acquire exclusive lock on $file");
    }

    $content = stream_get_contents($fp);
    $data = ($content !== '' && $content !== false) ? json_decode($content, true) : null;
    if ($data === null) {
        $data = $default;
    }

    $newData = $callback($data);

    $json = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Write to a temp file and rename for crash safety, while holding the lock
    $tmpFile = $file . '.tmp';
    if (file_put_contents($tmpFile, $json) !== false) {
        rename($tmpFile, $file);
    } else {
        // Fallback: write in-place
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $json);
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $newData;
}

/**
 * Acquire an exclusive file-based mutex lock for the duration of $callback.
 * Used to serialize ID generation + change creation to prevent race conditions
 * when multiple requests try to reserve framework IDs concurrently.
 *
 * @param callable $callback fn(): mixed
 * @return mixed   The return value of $callback
 */
function withIdLock(callable $callback): mixed {
    $lockFile = DATA_DIR . '/.id_lock';
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        throw new \RuntimeException("withIdLock: cannot open lock file $lockFile");
    }
    if (!flock($fp, LOCK_EX)) {
        error_log("withIdLock: failed to acquire exclusive lock on $lockFile");
    }
    try {
        return $callback();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function generateUUID(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function getUsers(): array {
    $data = readJSON(USERS_FILE);
    return is_array($data) ? $data : [];
}

function saveUsers(array $users): bool {
    return writeJSON(USERS_FILE, $users);
}

function getFramework(): array {
    $data = readJSON(FRAMEWORK_FILE);
    return is_array($data) ? $data : ['type' => 'bundle', 'objects' => []];
}

function saveFramework(array $fw): bool {
    return writeJSON(FRAMEWORK_FILE, $fw);
}

function getChangesData(): array {
    $data = readJSON(CHANGES_FILE);
    return is_array($data) ? $data : ['changes' => []];
}

function saveChangesData(array $data): bool {
    return writeJSON(CHANGES_FILE, $data);
}

function addChange(array $change): string {
    $change['id'] = generateUUID();
    $change['created_at'] = date('c');
    $change['status'] = 'pending';
    modifyJSON(CHANGES_FILE, ['changes' => []], function ($data) use ($change) {
        $data['changes'][] = $change;
        return $data;
    });
    return $change['id'];
}

function updateChange(string $changeId, array $updates): bool {
    $found = false;
    modifyJSON(CHANGES_FILE, ['changes' => []], function ($data) use ($changeId, $updates, &$found) {
        foreach ($data['changes'] as &$change) {
            if ($change['id'] === $changeId) {
                foreach ($updates as $k => $v) {
                    $change[$k] = $v;
                }
                $found = true;
                break;
            }
        }
        unset($change);
        return $data;
    });
    return $found;
}

function getReleases(): array {
    $data = readJSON(RELEASES_INDEX);
    return is_array($data) ? $data : ['releases' => []];
}

function addRelease(array $release): bool {
    modifyJSON(RELEASES_INDEX, ['releases' => []], function ($data) use ($release) {
        $data['releases'][] = $release;
        return $data;
    });
    return true;
}

// ── Framework profile helpers ──────────────────────────────────────────────────

/** Return the active framework profile (merged config), cached per request. */
function getProfile(): array {
    global $__profileCache;
    if (!isset($__profileCache)) {
        $__profileCache = getConfig()['profile'] ?? [];
    }
    return $__profileCache;
}

/** Fetch a top-level scalar value from the active profile. */
function profileValue(string $key, mixed $default = null): mixed {
    $p = getProfile();
    return $p[$key] ?? $default;
}

/** Return the configuration for one sub-framework slug. */
function subframeworkConf(string $framework): array {
    $subs = getProfile()['subframeworks'] ?? [];
    if (!isset($subs[$framework])) {
        throw new \InvalidArgumentException("Unknown framework: $framework");
    }
    return $subs[$framework];
}

/** List the configured sub-framework slugs (e.g. the configured sub-framework keys). */
function frameworkSlugs(): array {
    return array_keys(getProfile()['subframeworks'] ?? []);
}

/** Build a STIX custom-property name from the profile namespace, e.g. stixProp('framework') -> "<prefix>_framework". */
function stixProp(string $suffix): string {
    return profileValue('stix_property_prefix', 'x_framework') . '_' . $suffix;
}

// ── Framework helpers ─────────────────────────────────────────────────────────

/**
 * Map a framework identifier to its STIX kill_chain_name value (from profile).
 */
function frameworkToKillChain(string $framework): string {
    return subframeworkConf($framework)['kill_chain_name'];
}

/**
 * Map a framework identifier to its ID prefix (from profile).
 */
function frameworkToPrefix(string $framework): string {
    return subframeworkConf($framework)['id_prefix'];
}

/**
 * Reverse of frameworkToKillChain: resolve a STIX kill_chain_name back to its
 * sub-framework slug. Returns '' if no configured sub-framework matches.
 */
function killChainToFramework(string $killChainName): string {
    foreach (getProfile()['subframeworks'] ?? [] as $slug => $conf) {
        if (($conf['kill_chain_name'] ?? '') === $killChainName) {
            return $slug;
        }
    }
    return '';
}

// ── ID generation ─────────────────────────────────────────────────────────────

function getPendingFrameworkIds(string $framework): array {
    $data = getChangesData();
    $ids = [];
    foreach ($data['changes'] ?? [] as $ch) {
        if (($ch['status'] ?? '') !== 'pending') continue;
        if (($ch['type'] ?? '') !== 'add') continue;
        if (($ch['framework'] ?? '') !== $framework) continue;
        if (!empty($ch['preview_framework_id'])) {
            $ids[] = $ch['preview_framework_id'];
        }
    }
    return $ids;
}

/**
 * Generate the next top-level (tactic) ID for a sub-framework.
 * Format: OBS### or ASM### (3-digit, zero-padded).
 */
function generateNextTacticId(array $fw, array $excludeIds, string $framework): string {
    $prefix = frameworkToPrefix($framework);
    $propFw = stixProp('framework');
    $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
    $used = [];
    $max  = 0;
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'x-mitre-tactic') continue;
        if (($obj[$propFw] ?? '') !== $framework) continue;
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id']) && preg_match($pattern, $ref['external_id'], $m)) {
                $n = (int)$m[1];
                $used[$n] = true;
                $max = max($max, $n);
            }
        }
    }
    foreach ($excludeIds as $eid) {
        if (preg_match($pattern, $eid, $m)) {
            $n = (int)$m[1];
            $used[$n] = true;
            $max = max($max, $n);
        }
    }
    $next = $max + 1;
    while (isset($used[$next])) $next++;
    return $prefix . str_pad($next, 3, '0', STR_PAD_LEFT);
}

/**
 * Generate the next technique ID under a parent tactic.
 * Format: <PREFIX>###.### (3-digit suffix, zero-padded).
 * Only considers direct children (2-segment IDs), not sub-techniques (3-segment).
 */
function generateNextTechniqueId(array $fw, string $parentId, array $excludeIds): string {
    $used   = [];
    $max    = 0;
    $prefix = $parentId . '.';
    $segmentCount = substr_count($parentId, '.') + 2; // e.g. OBS001 -> expect 2-segment children
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        if (!empty($obj['x_mitre_is_subtechnique'])) continue; // skip sub-techniques
        foreach ($obj['external_references'] ?? [] as $ref) {
            $extId = $ref['external_id'] ?? '';
            if (str_starts_with($extId, $prefix) && substr_count($extId, '.') === ($segmentCount - 1) && preg_match('/\.(\d+)$/', $extId, $m)) {
                $n = (int)$m[1];
                $used[$n] = true;
                $max = max($max, $n);
            }
        }
    }
    foreach ($excludeIds as $eid) {
        if (str_starts_with($eid, $prefix) && substr_count($eid, '.') === ($segmentCount - 1) && preg_match('/\.(\d+)$/', $eid, $m)) {
            $n = (int)$m[1];
            $used[$n] = true;
            $max = max($max, $n);
        }
    }
    $next = $max + 1;
    while (isset($used[$next])) $next++;
    return $parentId . '.' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

/**
 * Generate the next sub-technique ID under a parent technique.
 * Format: OBS###.###.### or ASM###.###.### (3-digit suffix, zero-padded).
 */
function generateNextSubTechniqueId(array $fw, string $parentTechniqueId, array $excludeIds): string {
    $used   = [];
    $max    = 0;
    $prefix = $parentTechniqueId . '.';
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        foreach ($obj['external_references'] ?? [] as $ref) {
            $extId = $ref['external_id'] ?? '';
            if (str_starts_with($extId, $prefix) && preg_match('/\.(\d+)$/', $extId, $m)) {
                $n = (int)$m[1];
                $used[$n] = true;
                $max = max($max, $n);
            }
        }
    }
    foreach ($excludeIds as $eid) {
        if (str_starts_with($eid, $prefix) && preg_match('/\.(\d+)$/', $eid, $m)) {
            $n = (int)$m[1];
            $used[$n] = true;
            $max = max($max, $n);
        }
    }
    $next = $max + 1;
    while (isset($used[$next])) $next++;
    return $parentTechniqueId . '.' . str_pad($next, 3, '0', STR_PAD_LEFT);
}

// ── Extract helpers ───────────────────────────────────────────────────────────

function extractTactics(array $fw, string $framework): array {
    $tactics = [];
    $propFw = stixProp('framework');
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'x-mitre-tactic') continue;
        if (($obj[$propFw] ?? '') !== $framework) continue;
        $frameworkId = '';
        $url = '';
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) $frameworkId = $ref['external_id'];
            if (!empty($ref['url']))         $url         = $ref['url'];
        }
        $deprecated = !empty($obj['x_mitre_deprecated']) || !empty($obj['revoked']);
        $tactics[] = [
            'stix_id'      => $obj['id'] ?? '',
            'framework_id' => $frameworkId,
            'name'         => $obj['name'] ?? '',
            'description'  => $obj['description'] ?? '',
            'shortname'    => $obj['x_mitre_shortname'] ?? '',
            'url'          => $url,
            'deprecated'   => $deprecated,
            'replaced_by'  => $obj['x_mitre_replaced_by'] ?? null,
        ];
    }
    usort($tactics, fn($a, $b) => strnatcasecmp($a['framework_id'], $b['framework_id']));
    return $tactics;
}

function extractTechniques(array $fw, ?string $tacticShortname = null, ?string $framework = null): array {
    $killChain = $framework ? frameworkToKillChain($framework) : null;

    // Build related-to map (bidirectional): STIX ID → [['stix_id' => ..., 'description' => ...]]
    $relatedMap = [];
    // Build subtechnique-of map: sub-technique STIX ID → parent technique STIX ID
    $parentMap = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'relationship') continue;
        if (($obj['relationship_type'] ?? '') === 'related-to') {
            $src = $obj['source_ref'] ?? '';
            $tgt = $obj['target_ref'] ?? '';
            $desc = $obj['description'] ?? '';
            if ($src && $tgt) {
                $relatedMap[$src][] = ['stix_id' => $tgt, 'description' => $desc];
                $relatedMap[$tgt][] = ['stix_id' => $src, 'description' => $desc];
            }
        } elseif (($obj['relationship_type'] ?? '') === 'subtechnique-of') {
            $src = $obj['source_ref'] ?? '';
            $tgt = $obj['target_ref'] ?? '';
            if ($src && $tgt) {
                $parentMap[$src] = $tgt;
            }
        }
    }

    // Build stix_id -> framework_id map and stix_id -> name map (all attack-patterns)
    $idMap = [];
    $nameMap = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        $nameMap[$obj['id']] = $obj['name'] ?? '';
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) { $idMap[$obj['id']] = $ref['external_id']; break; }
        }
    }

    $techniques = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;

        // Filter by framework via kill_chain_name
        if ($killChain !== null) {
            $matchesFramework = false;
            foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
                if (($kcp['kill_chain_name'] ?? '') === $killChain) {
                    $matchesFramework = true;
                    break;
                }
            }
            if (!$matchesFramework) continue;
        }

        $frameworkId = '';
        $url = '';
        $reports = [];
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (($ref['source_name'] ?? '') === 'report') {
                $reports[] = [
                    'url'     => $ref['url'] ?? '',
                    'excerpt' => $ref['description'] ?? '',
                ];
            } else {
                if (!empty($ref['external_id'])) $frameworkId = $ref['external_id'];
                if (!empty($ref['url']))         $url         = $ref['url'];
            }
        }

        $tacticShortnames = [];
        foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
            if (!empty($kcp['phase_name'])) $tacticShortnames[] = $kcp['phase_name'];
        }

        if ($tacticShortname !== null && !in_array($tacticShortname, $tacticShortnames, true)) {
            continue;
        }

        $stixId     = $obj['id'] ?? '';
        $deprecated = !empty($obj['x_mitre_deprecated']) || !empty($obj['revoked']);
        $isSubtechnique = !empty($obj['x_mitre_is_subtechnique']);

        // Resolve parent technique for sub-techniques
        $parentTechnique = null;
        if ($isSubtechnique && isset($parentMap[$stixId])) {
            $parentStixId = $parentMap[$stixId];
            $parentTechnique = [
                'stix_id'      => $parentStixId,
                'framework_id' => $idMap[$parentStixId] ?? '',
                'name'         => $nameMap[$parentStixId] ?? '',
            ];
        }

        // Build related techniques list with IDs, names, and descriptions
        $relatedTechniques = [];
        $seenRelated = [];
        foreach ($relatedMap[$stixId] ?? [] as $rel) {
            $relStixId = $rel['stix_id'];
            if (isset($idMap[$relStixId]) && !isset($seenRelated[$relStixId])) {
                $seenRelated[$relStixId] = true;
                $relatedTechniques[] = [
                    'stix_id'      => $relStixId,
                    'framework_id' => $idMap[$relStixId],
                    'name'         => $nameMap[$relStixId] ?? '',
                    'description'  => $rel['description'],
                ];
            }
        }
        usort($relatedTechniques, fn($a, $b) => strnatcasecmp($a['framework_id'], $b['framework_id']));

        $techniques[] = [
            'stix_id'            => $stixId,
            'framework_id'       => $frameworkId,
            'name'               => $obj['name'] ?? '',
            'description'        => $obj['description'] ?? '',
            'tactic_shortnames'  => $tacticShortnames,
            'platforms'          => $obj['x_mitre_platforms'] ?? [],
            'url'                => $url,
            'deprecated'         => $deprecated,
            'replaced_by'        => $obj['x_mitre_replaced_by'] ?? null,
            'related_reports'    => $reports,
            'related_techniques' => $relatedTechniques,
            'is_subtechnique'    => $isSubtechnique,
            'parent_technique'   => $parentTechnique,
        ];
    }

    usort($techniques, fn($a, $b) => strnatcasecmp($a['framework_id'], $b['framework_id']));
    return $techniques;
}

// ── Config helpers ────────────────────────────────────────────────────────────

function getConfig(): array {
    $defaults = require CONFIG_FILE;

    // Runtime overrides from data/config.json
    $overrides = readJSON(DATA_DIR . '/config.json');
    if (is_array($overrides)) {
        foreach ($overrides as $section => $values) {
            if (is_array($values) && isset($defaults[$section]) && is_array($defaults[$section])) {
                // Deep-merge so partial overrides (e.g. a profile with only some
                // keys) fall back to defaults for anything not supplied.
                $defaults[$section] = _deepMerge($defaults[$section], $values);
                // The sub-framework set must REPLACE the defaults wholesale (not
                // union) — otherwise the default primary/secondary would leak in.
                if ($section === 'profile' && isset($values['subframeworks']) && is_array($values['subframeworks'])) {
                    $defaults['profile']['subframeworks'] = $values['subframeworks'];
                }
            } else {
                $defaults[$section] = $values;
            }
        }
    }

    return $defaults;
}

/** Recursively merge associative arrays; list arrays and scalars are replaced. */
function _deepMerge(array $base, array $override): array {
    foreach ($override as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !array_is_list($v)) {
            $base[$k] = _deepMerge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function saveConfig(array $values): bool {
    global $__profileCache;
    $__profileCache = null; // bust cache so subsequent reads see new profile
    // Merge into the existing runtime overrides so a partial save (e.g. GitHub
    // settings) never drops other sections such as the framework profile.
    $existing = readJSON(DATA_DIR . '/config.json');
    if (!is_array($existing)) $existing = [];
    return writeJSON(DATA_DIR . '/config.json', _deepMerge($existing, $values));
}

/** Replace the entire framework profile in the runtime config (clean replace, not merge). */
function saveProfile(array $profile): bool {
    global $__profileCache;
    $__profileCache = null;
    $existing = readJSON(DATA_DIR . '/config.json');
    if (!is_array($existing)) $existing = [];
    $existing['profile'] = $profile;
    return writeJSON(DATA_DIR . '/config.json', $existing);
}

/**
 * Permanently delete all stored data for sub-frameworks that exist in the
 * CURRENT (active) profile but are absent from $newProfile: their tactics,
 * techniques and sub-techniques, any relationships that reference them, and
 * their change proposals. Call BEFORE saveProfile($newProfile). Returns the
 * number of STIX objects removed.
 */
function deleteRemovedSubframeworkData(array $newProfile): int {
    $old     = getProfile();
    $oldSubs = $old['subframeworks'] ?? [];
    $newSubs = $newProfile['subframeworks'] ?? [];
    $removed = array_values(array_diff(array_keys($oldSubs), array_keys($newSubs)));
    if (!$removed) return 0;

    $removedSet = array_flip($removed);
    $oldPropFw  = ($old['stix_property_prefix'] ?? 'x_framework') . '_framework';
    $kcRemove   = [];
    foreach ($removed as $slug) {
        $kc = $oldSubs[$slug]['kill_chain_name'] ?? '';
        if ($kc !== '') $kcRemove[$kc] = true;
    }

    $deleted = 0;
    modifyJSON(FRAMEWORK_FILE, ['objects' => []], function ($fw) use ($removedSet, $oldPropFw, $kcRemove, &$deleted) {
        $delIds = [];
        $keep   = [];
        foreach ($fw['objects'] ?? [] as $obj) {
            $type = $obj['type'] ?? '';
            $belongs = false;
            if ($type === 'x-mitre-tactic' || $type === 'attack-pattern') {
                if (isset($obj[$oldPropFw]) && isset($removedSet[$obj[$oldPropFw]])) {
                    $belongs = true;
                }
                if (!$belongs && $type === 'attack-pattern') {
                    foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
                        if (isset($kcRemove[$kcp['kill_chain_name'] ?? ''])) { $belongs = true; break; }
                    }
                }
            }
            if ($belongs) { $delIds[$obj['id'] ?? ''] = true; continue; }
            $keep[] = $obj;
        }
        // Drop relationships (related-to, subtechnique-of) that reference a deleted object.
        $final = [];
        foreach ($keep as $obj) {
            if (($obj['type'] ?? '') === 'relationship'
                && (isset($delIds[$obj['source_ref'] ?? '']) || isset($delIds[$obj['target_ref'] ?? '']))) {
                continue;
            }
            $final[] = $obj;
        }
        $deleted = count($fw['objects'] ?? []) - count($final);
        $fw['objects'] = $final;
        return $fw;
    });

    // Remove change proposals targeting the removed sub-frameworks.
    modifyJSON(CHANGES_FILE, ['changes' => []], function ($data) use ($removedSet) {
        $data['changes'] = array_values(array_filter(
            $data['changes'] ?? [],
            fn($c) => !isset($removedSet[$c['framework'] ?? ''])
        ));
        return $data;
    });

    return $deleted;
}

/**
 * Re-tag stored data when a KEPT sub-framework's structural values change, so
 * its objects are not orphaned. Handles two cases against the CURRENT profile:
 *   - the STIX property prefix changed (renames the membership/version keys on
 *     every object), and
 *   - a sub-framework's kill_chain_name changed (remaps that value on
 *     attack-patterns).
 * Call BEFORE saveProfile($newProfile). Returns the number of objects touched.
 */
function migrateProfileStructuralChanges(array $newProfile): int {
    $old       = getProfile();
    $oldPrefix = $old['stix_property_prefix'] ?? 'x_framework';
    $newPrefix = $newProfile['stix_property_prefix'] ?? $oldPrefix;
    $oldSubs   = $old['subframeworks'] ?? [];
    $newSubs   = $newProfile['subframeworks'] ?? [];

    // kill_chain remap for slugs present in both, whose kill_chain_name changed.
    $kcMap = [];
    foreach ($newSubs as $slug => $conf) {
        if (!isset($oldSubs[$slug])) continue; // added or removed handled elsewhere
        $oldKc = $oldSubs[$slug]['kill_chain_name'] ?? '';
        $newKc = $conf['kill_chain_name'] ?? '';
        if ($oldKc !== '' && $newKc !== '' && $oldKc !== $newKc) {
            $kcMap[$oldKc] = $newKc;
        }
    }

    $prefixChanged = ($oldPrefix !== $newPrefix);
    if (!$prefixChanged && !$kcMap) return 0;

    $oldFwKey  = $oldPrefix . '_framework';
    $newFwKey  = $newPrefix . '_framework';
    $oldVerKey = $oldPrefix . '_version';
    $newVerKey = $newPrefix . '_version';

    $changed = 0;
    modifyJSON(FRAMEWORK_FILE, ['objects' => []], function ($fw) use ($prefixChanged, $kcMap, $oldFwKey, $newFwKey, $oldVerKey, $newVerKey, &$changed) {
        foreach ($fw['objects'] as &$obj) {
            $touched = false;
            if ($prefixChanged) {
                if (array_key_exists($oldFwKey, $obj))  { $obj[$newFwKey]  = $obj[$oldFwKey];  unset($obj[$oldFwKey]);  $touched = true; }
                if (array_key_exists($oldVerKey, $obj)) { $obj[$newVerKey] = $obj[$oldVerKey]; unset($obj[$oldVerKey]); $touched = true; }
            }
            if ($kcMap && ($obj['type'] ?? '') === 'attack-pattern' && !empty($obj['kill_chain_phases'])) {
                foreach ($obj['kill_chain_phases'] as &$kcp) {
                    $kc = $kcp['kill_chain_name'] ?? '';
                    if (isset($kcMap[$kc])) { $kcp['kill_chain_name'] = $kcMap[$kc]; $touched = true; }
                }
                unset($kcp);
            }
            if ($touched) $changed++;
        }
        unset($obj);
        return $fw;
    });
    return $changed;
}

// ── Publish helpers ───────────────────────────────────────────────────────────

function getPublishData(): array {
    $data = readJSON(PUBLISH_FILE);
    return is_array($data) ? $data : ['publications' => [], 'last_published_at' => null];
}

function savePublishData(array $data): bool {
    return writeJSON(PUBLISH_FILE, $data);
}

function addPublication(array $publication): bool {
    modifyJSON(PUBLISH_FILE, ['publications' => [], 'last_published_at' => null], function ($data) use ($publication) {
        $data['publications'][] = $publication;
        $data['last_published_at'] = $publication['published_at'] ?? date('c');
        return $data;
    });
    return true;
}

function getTranslationCache(): array {
    $data = readJSON(TRANSLATION_CACHE_FILE);
    return is_array($data) ? $data : [];
}

function saveTranslationCache(array $data): bool {
    return writeJSON(TRANSLATION_CACHE_FILE, $data);
}

function getUnpublishedChanges(): array {
    $data = getChangesData();
    return array_values(array_filter(
        $data['changes'] ?? [],
        fn($c) => ($c['status'] ?? '') === 'approved' && empty($c['published_at'])
    ));
}

function markChangesPublished(array $changeIds): void {
    $now = date('c');
    modifyJSON(CHANGES_FILE, ['changes' => []], function ($data) use ($changeIds, $now) {
        foreach ($data['changes'] as &$change) {
            if (in_array($change['id'], $changeIds, true)) {
                $change['published_at'] = $now;
            }
        }
        unset($change);
        return $data;
    });
}

// ── Submissions helpers ──────────────────────────────────────────────────────

function getSubmissions(): array {
    $data = readJSON(SUBMISSIONS_FILE);
    return is_array($data) ? $data : ['submissions' => []];
}

function addSubmission(array $submission): string {
    $submission['id'] = generateUUID();
    $submission['created_at'] = date('c');
    $submission['status'] = 'new';
    modifyJSON(SUBMISSIONS_FILE, ['submissions' => []], function ($data) use ($submission) {
        $data['submissions'][] = $submission;
        return $data;
    });
    return $submission['id'];
}

function updateSubmission(string $id, array $updates): bool {
    $found = false;
    modifyJSON(SUBMISSIONS_FILE, ['submissions' => []], function ($data) use ($id, $updates, &$found) {
        foreach ($data['submissions'] as &$sub) {
            if ($sub['id'] === $id) {
                foreach ($updates as $k => $v) {
                    $sub[$k] = $v;
                }
                $found = true;
                break;
            }
        }
        unset($sub);
        return $data;
    });
    return $found;
}
