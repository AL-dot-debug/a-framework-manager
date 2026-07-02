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
 * Framework Manager — Minimal YAML Emitter & STIX-to-YAML Converters
 *
 * Handles the simple flat format used for the framework YAML files:
 * scalars, multiline block scalars, and lists of strings.
 */

require_once __DIR__ . '/data.php';

// ── Generic YAML emitter ──────────────────────────────────────────────────────

/**
 * Emit a flat associative array as a YAML document.
 *
 * Supported value types:
 *   string  → scalar or block scalar (|) if multiline
 *   bool    → true/false
 *   array   → list of strings (- item)
 *   null    → omitted
 */
function yamlEmit(array $data): string {
    $lines = [];
    foreach ($data as $key => $value) {
        if ($value === null) continue;

        if (is_bool($value)) {
            $lines[] = $key . ': ' . ($value ? 'true' : 'false');
        } elseif (is_array($value)) {
            if (empty($value)) continue;
            // Check if it's a list of associative arrays (objects)
            if (isset($value[0]) && is_array($value[0])) {
                $lines[] = $key . ':';
                foreach ($value as $item) {
                    $first = true;
                    foreach ($item as $subKey => $subVal) {
                        if ($subVal === null) continue;
                        $prefix = $first ? '  - ' : '    ';
                        $first = false;
                        $sv = is_bool($subVal) ? ($subVal ? 'true' : 'false') : yamlEscapeScalar((string)$subVal);
                        $lines[] = $prefix . $subKey . ': ' . $sv;
                    }
                }
            } else {
                $lines[] = $key . ':';
                foreach ($value as $item) {
                    $lines[] = '  - ' . yamlEscapeScalar((string)$item);
                }
            }
        } elseif (is_string($value)) {
            $trimmed = rtrim($value);
            if ($trimmed === '') {
                // Empty string — omit or use empty quotes
                $lines[] = $key . ': ""';
            } elseif (str_contains($trimmed, "\n")) {
                // Multiline → block scalar
                $lines[] = $key . ': |';
                foreach (explode("\n", $trimmed) as $line) {
                    $lines[] = '  ' . $line;
                }
            } else {
                $lines[] = $key . ': ' . yamlEscapeScalar($trimmed);
            }
        }
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Escape a scalar string for YAML if it needs quoting.
 */
function yamlEscapeScalar(string $value): string {
    // Values that could be misinterpreted: booleans, numbers, or contain special chars
    if ($value === '') return '""';

    // Multiline strings: use double-quoted form with escaped newlines
    if (str_contains($value, "\n")) {
        $escaped = str_replace(['\\', '"', "\n", "\r", "\t"], ['\\\\', '\\"', '\\n', '\\r', '\\t'], $value);
        return '"' . $escaped . '"';
    }

    if (in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off'], true)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }
    if (preg_match('/^[\d.eE+-]+$/', $value) && is_numeric($value)) {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }
    // Quote if starts with special chars or contains : # { } [ ] , & * ? | - < > = ! % @ or backslash
    if (preg_match('/^[&*?|>\-!%@`\'"]/', $value) || preg_match('/[:#\[\]{}\\\\]/', $value)) {
        // Use double quotes, escaping internal double quotes and backslashes
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }
    return $value;
}

// ── STIX → YAML converters ───────────────────────────────────────────────────

/**
 * Convert a STIX x-mitre-tactic object to simplified YAML.
 */
function stixTacticToYaml(array $stixObj, string $framework): string {
    $frameworkId = '';
    foreach ($stixObj['external_references'] ?? [] as $ref) {
        if (!empty($ref['external_id'])) { $frameworkId = $ref['external_id']; break; }
    }

    $data = [
        'id'          => $frameworkId,
        'framework'   => $framework,
        'name'        => $stixObj['name'] ?? '',
        'description' => $stixObj['description'] ?? '',
        'shortname'   => $stixObj['x_mitre_shortname'] ?? '',
        'created'     => $stixObj['created'] ?? '',
        'modified'    => $stixObj['modified'] ?? '',
    ];

    $deprecated = !empty($stixObj['x_mitre_deprecated']) || !empty($stixObj['revoked']);
    if ($deprecated) {
        $data['deprecated'] = true;
    }
    if (!empty($stixObj['x_mitre_replaced_by'])) {
        $data['replaced_by'] = $stixObj['x_mitre_replaced_by'];
    }

    return yamlEmit($data);
}

/**
 * Convert a STIX attack-pattern object to simplified YAML.
 */
function stixTechniqueToYaml(array $stixObj, array $relatedTechniques = [], ?string $framework = null, ?string $parentId = null): string {
    $frameworkId = '';
    $reports = [];
    foreach ($stixObj['external_references'] ?? [] as $ref) {
        if (($ref['source_name'] ?? '') === 'report') {
            $reports[] = [
                'url'     => $ref['url'] ?? '',
                'excerpt' => $ref['description'] ?? '',
            ];
        } elseif (!empty($ref['external_id'])) {
            $frameworkId = $ref['external_id'];
        }
    }

    $tactics = [];
    foreach ($stixObj['kill_chain_phases'] ?? [] as $kcp) {
        if (!empty($kcp['phase_name'])) $tactics[] = $kcp['phase_name'];
    }

    // Determine framework from kill_chain_phases if not provided
    if ($framework === null) {
        $kcName = $stixObj['kill_chain_phases'][0]['kill_chain_name'] ?? '';
        $framework = killChainToFramework($kcName);
    }

    $platforms = $stixObj['x_mitre_platforms'] ?? [];

    $isSubtechnique = !empty($stixObj['x_mitre_is_subtechnique']);

    $data = [
        'id'          => $frameworkId,
        'framework'   => $framework,
        'name'        => $stixObj['name'] ?? '',
    ];

    if ($isSubtechnique) {
        $data['is_subtechnique'] = true;
        if ($parentId) {
            $data['parent_technique'] = $parentId;
        }
    }

    $data['description'] = $stixObj['description'] ?? '';
    $data['created']     = $stixObj['created'] ?? '';
    $data['modified']    = $stixObj['modified'] ?? '';

    if (!empty($tactics)) {
        $data['tactics'] = $tactics;
    }

    if (!empty($platforms)) {
        $data['platforms'] = $platforms;
    }

    if (!empty($reports)) {
        $data['reports'] = $reports;
    }

    if (!empty($relatedTechniques)) {
        usort($relatedTechniques, fn($a, $b) => strcmp($a['id'], $b['id']));
        $data['related_techniques'] = array_map(function ($rt) {
            $entry = ['id' => $rt['id']];
            if (!empty($rt['description'])) {
                $entry['description'] = $rt['description'];
            }
            return $entry;
        }, $relatedTechniques);
    }

    $deprecated = !empty($stixObj['x_mitre_deprecated']) || !empty($stixObj['revoked']);
    if ($deprecated) {
        $data['deprecated'] = true;
    }
    if (!empty($stixObj['x_mitre_replaced_by'])) {
        $data['replaced_by'] = $stixObj['x_mitre_replaced_by'];
    }

    return yamlEmit($data);
}

/**
 * Convert the entire framework bundle to an array of YAML file entries.
 *
 * Returns: [['path' => 'objects/tactics/TA02.yaml', 'content' => '...'], ...]
 */
function frameworkToYamlFiles(array $fw, string $framework): array {
    $files = [];
    $killChainName = frameworkToKillChain($framework);

    // Build related-to map (bidirectional): STIX ID → ['stix_id' => ..., 'description' => ...]
    // Build subtechnique-of map: sub-technique STIX ID → parent technique STIX ID
    $relatedMap = [];
    $parentMap = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'relationship') continue;
        $relType = $obj['relationship_type'] ?? '';
        if ($relType === 'related-to') {
            $src = $obj['source_ref'] ?? '';
            $tgt = $obj['target_ref'] ?? '';
            $desc = $obj['description'] ?? '';
            if ($src && $tgt) {
                $relatedMap[$src][] = ['stix_id' => $tgt, 'description' => $desc];
                $relatedMap[$tgt][] = ['stix_id' => $src, 'description' => $desc];
            }
        } elseif ($relType === 'subtechnique-of') {
            $src = $obj['source_ref'] ?? '';
            $tgt = $obj['target_ref'] ?? '';
            if ($src && $tgt) {
                $parentMap[$src] = $tgt;
            }
        }
    }

    // Build STIX ID → framework ID map for all attack-patterns
    $idMap = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) { $idMap[$obj['id']] = $ref['external_id']; break; }
        }
    }

    // Process tactics — filter by framework membership
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'x-mitre-tactic') continue;
        if (($obj[stixProp('framework')] ?? '') !== $framework) continue;
        $frameworkId = '';
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) { $frameworkId = $ref['external_id']; break; }
        }
        if (!$frameworkId) continue;
        $files[] = [
            'path'    => $framework . '/objects/tactics/' . $frameworkId . '.yaml',
            'content' => stixTacticToYaml($obj, $framework),
        ];
    }

    // Process techniques — filter by kill_chain_name
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        // Check if any kill_chain_phase matches this framework
        $matches = false;
        foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
            if (($kcp['kill_chain_name'] ?? '') === $killChainName) { $matches = true; break; }
        }
        if (!$matches) continue;

        $frameworkId = '';
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) { $frameworkId = $ref['external_id']; break; }
        }
        if (!$frameworkId) continue;

        // Resolve related techniques with descriptions
        $relatedTechniques = [];
        $seenRelated = [];
        foreach ($relatedMap[$obj['id']] ?? [] as $rel) {
            $relStixId = $rel['stix_id'];
            if (isset($idMap[$relStixId]) && !isset($seenRelated[$relStixId])) {
                $seenRelated[$relStixId] = true;
                $relatedTechniques[] = [
                    'id' => $idMap[$relStixId],
                    'description' => $rel['description'],
                ];
            }
        }

        $isSubtechnique = !empty($obj['x_mitre_is_subtechnique']);
        $subdir = $isSubtechnique ? 'subtechniques' : 'techniques';
        $parentId = isset($parentMap[$obj['id']]) ? ($idMap[$parentMap[$obj['id']]] ?? null) : null;

        $files[] = [
            'path'    => $framework . '/objects/' . $subdir . '/' . $frameworkId . '.yaml',
            'content' => stixTechniqueToYaml($obj, $relatedTechniques, $framework, $parentId),
        ];
    }

    // Sort by path for deterministic output
    usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));

    return $files;
}

// ── Documentation generator ──────────────────────────────────────────────────

/**
 * Generate Markdown documentation files for all tactics and techniques.
 *
 * Returns: [['path' => 'documentation/tactics/TA02.md', 'content' => '...'], ...]
 */
function frameworkToDocFiles(array $fw, string $framework, string $repoOwner = '', string $repoName = ''): array {
    $files = [];
    $killChainName = frameworkToKillChain($framework);

    // GitHub base URL for linking to documentation files on main branch
    $ghBase = ($repoOwner && $repoName)
        ? "https://github.com/{$repoOwner}/{$repoName}/blob/main/{$framework}/documentation"
        : '';

    // Build lookup maps
    $relatedMap = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'relationship') continue;
        $relType = $obj['relationship_type'] ?? '';
        if ($relType === 'related-to') {
            $src = $obj['source_ref'] ?? '';
            $tgt = $obj['target_ref'] ?? '';
            if ($src && $tgt) {
                $relatedMap[$src][] = $tgt;
                $relatedMap[$tgt][] = $src;
            }
        }
    }

    $idMap = [];
    $nameMap = [];
    $tacticsByShortname = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        $type = $obj['type'] ?? '';
        if ($type === 'attack-pattern') {
            $nameMap[$obj['id']] = $obj['name'] ?? '';
            foreach ($obj['external_references'] ?? [] as $ref) {
                if (!empty($ref['external_id'])) { $idMap[$obj['id']] = $ref['external_id']; break; }
            }
        } elseif ($type === 'x-mitre-tactic') {
            if (($obj[stixProp('framework')] ?? '') !== $framework) continue;
            $shortname = $obj['x_mitre_shortname'] ?? '';
            if ($shortname) {
                $frameworkId = '';
                foreach ($obj['external_references'] ?? [] as $ref) {
                    if (!empty($ref['external_id'])) { $frameworkId = $ref['external_id']; break; }
                }
                $tacticsByShortname[$shortname] = [
                    'framework_id' => $frameworkId,
                    'name'      => $obj['name'] ?? '',
                ];
            }
        }
    }

    // Build subtechnique-of map and children map
    $docParentMap = [];
    $docChildrenMap = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'relationship') continue;
        if (($obj['relationship_type'] ?? '') === 'subtechnique-of') {
            $src = $obj['source_ref'] ?? '';
            $tgt = $obj['target_ref'] ?? '';
            if ($src && $tgt) {
                $docParentMap[$src] = $tgt;
                $docChildrenMap[$tgt][] = $src;
            }
        }
    }

    // Collect techniques per tactic shortname (filtered by kill chain, excluding sub-techniques)
    $techniquesByTactic = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        if (!empty($obj['x_mitre_is_subtechnique'])) continue;
        foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
            if (($kcp['kill_chain_name'] ?? '') !== $killChainName) continue;
            $phase = $kcp['phase_name'] ?? '';
            if ($phase) $techniquesByTactic[$phase][] = $obj['id'];
        }
    }

    // ── Tactic docs ──
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'x-mitre-tactic') continue;
        if (($obj[stixProp('framework')] ?? '') !== $framework) continue;
        $frameworkId = '';
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) { $frameworkId = $ref['external_id']; break; }
        }
        if (!$frameworkId) continue;

        $shortname  = $obj['x_mitre_shortname'] ?? '';
        $deprecated = !empty($obj['x_mitre_deprecated']) || !empty($obj['revoked']);
        $docUrl     = $ghBase ? "{$ghBase}/tactics/{$frameworkId}.md" : '';

        $md = "# {$frameworkId}: {$obj['name']}\n\n";
        if ($deprecated) {
            $md .= "> **DEPRECATED**";
            if (!empty($obj['x_mitre_replaced_by'])) {
                $md .= " — replaced by [{$obj['x_mitre_replaced_by']}]({$obj['x_mitre_replaced_by']}.md)";
            }
            $md .= "\n\n";
        }

        $md .= "| Field | Value |\n|---|---|\n";
        $md .= "| **ID** | {$frameworkId} |\n";
        $md .= "| **Shortname** | `{$shortname}` |\n";
        $md .= "| **Created** | {$obj['created']} |\n";
        $md .= "| **Modified** | {$obj['modified']} |\n";
        if ($docUrl) $md .= "| **URL** | [{$docUrl}]({$docUrl}) |\n";
        $md .= "\n";

        $md .= "## Description\n\n" . ($obj['description'] ?: '*No description.*') . "\n\n";

        // List techniques in this tactic
        $techStixIds = $techniquesByTactic[$shortname] ?? [];
        if ($techStixIds) {
            $md .= "## Techniques\n\n";
            $md .= "| ID | Name |\n|---|---|\n";
            $entries = [];
            foreach ($techStixIds as $stixId) {
                $tid = $idMap[$stixId] ?? '';
                $tname = $nameMap[$stixId] ?? '';
                if ($tid) $entries[] = ['id' => $tid, 'name' => $tname];
            }
            usort($entries, fn($a, $b) => strnatcasecmp($a['id'], $b['id']));
            foreach ($entries as $e) {
                $md .= "| [{$e['id']}](../techniques/{$e['id']}.md) | {$e['name']} |\n";
            }
            $md .= "\n";
        }

        $files[] = [
            'path'    => $framework . '/documentation/tactics/' . $frameworkId . '.md',
            'content' => $md,
        ];
    }

    // ── Technique & Sub-technique docs ──
    foreach ($fw['objects'] ?? [] as $obj) {
        if (($obj['type'] ?? '') !== 'attack-pattern') continue;
        // Filter by kill chain
        $matches = false;
        foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
            if (($kcp['kill_chain_name'] ?? '') === $killChainName) { $matches = true; break; }
        }
        if (!$matches) continue;

        $frameworkId = '';
        $reports = [];
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (($ref['source_name'] ?? '') === 'report') {
                $reports[] = [
                    'url'     => $ref['url'] ?? '',
                    'excerpt' => $ref['description'] ?? '',
                ];
            } elseif (!empty($ref['external_id'])) {
                $frameworkId = $ref['external_id'];
            }
        }
        if (!$frameworkId) continue;

        $isSubtechnique = !empty($obj['x_mitre_is_subtechnique']);
        $deprecated     = !empty($obj['x_mitre_deprecated']) || !empty($obj['revoked']);
        $stixId         = $obj['id'];
        $docSubdir      = $isSubtechnique ? 'subtechniques' : 'techniques';
        $docUrl         = $ghBase ? "{$ghBase}/{$docSubdir}/{$frameworkId}.md" : '';

        $md = "# {$frameworkId}: {$obj['name']}\n\n";
        if ($deprecated) {
            $md .= "> **DEPRECATED**";
            if (!empty($obj['x_mitre_replaced_by'])) {
                $md .= " — replaced by [{$obj['x_mitre_replaced_by']}]({$obj['x_mitre_replaced_by']}.md)";
            }
            $md .= "\n\n";
        }

        $md .= "| Field | Value |\n|---|---|\n";
        $md .= "| **ID** | {$frameworkId} |\n";
        if ($isSubtechnique && isset($docParentMap[$stixId])) {
            $parentStixId = $docParentMap[$stixId];
            $parentId = $idMap[$parentStixId] ?? '';
            $parentName = $nameMap[$parentStixId] ?? '';
            if ($parentId) {
                $md .= "| **Parent** | [{$parentId}: {$parentName}](../techniques/{$parentId}.md) |\n";
            }
        }
        $md .= "| **Created** | {$obj['created']} |\n";
        $md .= "| **Modified** | {$obj['modified']} |\n";
        if ($docUrl) $md .= "| **URL** | [{$docUrl}]({$docUrl}) |\n";

        // Tactics
        $tactics = [];
        foreach ($obj['kill_chain_phases'] ?? [] as $kcp) {
            $phase = $kcp['phase_name'] ?? '';
            if ($phase && isset($tacticsByShortname[$phase])) {
                $t = $tacticsByShortname[$phase];
                $tactics[] = "[{$t['framework_id']}: {$t['name']}](../tactics/{$t['framework_id']}.md)";
            }
        }
        if ($tactics) {
            $md .= "| **Tactics** | " . implode(', ', $tactics) . " |\n";
        }

        // Platforms
        $platforms = $obj['x_mitre_platforms'] ?? [];
        if ($platforms) {
            $md .= "| **Platforms** | " . implode(', ', $platforms) . " |\n";
        }

        $md .= "\n";

        $md .= "## Description\n\n" . ($obj['description'] ?: '*No description.*') . "\n\n";

        // Sub-techniques (for parent techniques only)
        if (!$isSubtechnique) {
            $childStixIds = $docChildrenMap[$stixId] ?? [];
            if ($childStixIds) {
                $md .= "## Sub-techniques\n\n";
                $md .= "| ID | Name |\n|---|---|\n";
                $entries = [];
                foreach ($childStixIds as $cid) {
                    $tid = $idMap[$cid] ?? '';
                    $tname = $nameMap[$cid] ?? '';
                    if ($tid) $entries[] = ['id' => $tid, 'name' => $tname];
                }
                usort($entries, fn($a, $b) => strnatcasecmp($a['id'], $b['id']));
                foreach ($entries as $e) {
                    $md .= "| [{$e['id']}](../subtechniques/{$e['id']}.md) | {$e['name']} |\n";
                }
                $md .= "\n";
            }
        }

        // Related techniques
        $relatedStixIds = array_unique($relatedMap[$stixId] ?? []);
        if ($relatedStixIds) {
            $md .= "## Related Techniques\n\n";
            $md .= "| ID | Name |\n|---|---|\n";
            $entries = [];
            foreach ($relatedStixIds as $rid) {
                $tid = $idMap[$rid] ?? '';
                $tname = $nameMap[$rid] ?? '';
                if ($tid) $entries[] = ['id' => $tid, 'name' => $tname];
            }
            usort($entries, fn($a, $b) => strnatcasecmp($a['id'], $b['id']));
            foreach ($entries as $e) {
                $md .= "| [{$e['id']}]({$e['id']}.md) | {$e['name']} |\n";
            }
            $md .= "\n";
        }

        // Related reports
        if ($reports) {
            $md .= "## Related Reports\n\n";
            foreach ($reports as $i => $report) {
                $num = $i + 1;
                $md .= "**{$num}.** [{$report['url']}]({$report['url']})\n\n";
                if (!empty($report['excerpt'])) {
                    $md .= "> {$report['excerpt']}\n\n";
                }
            }
        }

        $files[] = [
            'path'    => $framework . '/documentation/' . $docSubdir . '/' . $frameworkId . '.md',
            'content' => $md,
        ];
    }

    // Sort by path
    usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));

    return $files;
}

// ── Translation YAML generator ───────────────────────────────────────────────

/**
 * Convert translated framework content to YAML file entries for publishing.
 *
 * @param array $translations [lang => [framework_id => ['name' => ..., 'description' => ...]]]
 * @return array [['path' => 'translations/fr/tactics/TA01.yaml', 'content' => '...'], ...]
 */
function translationsToYamlFiles(array $translations, string $framework): array {
    $files = [];

    foreach ($translations as $lang => $items) {
        foreach ($items as $frameworkId => $item) {
            // Route based on dot count: 0=tactics, 1=techniques, 2+=subtechniques
            $dotCount = substr_count($frameworkId, '.');
            $subdir = $dotCount >= 2 ? 'subtechniques' : ($dotCount === 1 ? 'techniques' : 'tactics');

            $data = [
                'id'          => $frameworkId,
                'framework'   => $framework,
                'name'        => $item['name'] ?? '',
                'description' => $item['description'] ?? '',
            ];

            $files[] = [
                'path'    => "$framework/translations/$lang/$subdir/$frameworkId.yaml",
                'content' => yamlEmit($data),
            ];
        }
    }

    usort($files, fn($a, $b) => strcmp($a['path'], $b['path']));
    return $files;
}
