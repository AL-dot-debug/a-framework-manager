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
 * Framework Manager — GitHub Integration
 *
 * GitHub App authentication (JWT / RS256), Git Data API client,
 * publish orchestrator, changelog generator, and schema/CI file generators.
 */

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/yaml.php';
require_once __DIR__ . '/deepl.php';

// ── GitHub App Authentication ─────────────────────────────────────────────────

/**
 * Generate a JWT for GitHub App authentication (RS256).
 */
function generateGitHubJWT(string $appId, string $privateKeyPath): string {
    if (!file_exists($privateKeyPath)) {
        throw new RuntimeException('GitHub App private key not found at: ' . $privateKeyPath);
    }

    $privateKey = openssl_pkey_get_private('file://' . $privateKeyPath);
    if (!$privateKey) {
        throw new RuntimeException('Failed to read GitHub App private key');
    }

    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $now = time();
    $payload = base64UrlEncode(json_encode([
        'iat' => $now - 60,
        'exp' => $now + (9 * 60),  // 9 minutes (max 10)
        'iss' => $appId,
    ]));

    $signature = '';
    openssl_sign($header . '.' . $payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

    return $header . '.' . $payload . '.' . base64UrlEncode($signature);
}

/**
 * Base64url encode (JWT-safe, no padding).
 */
function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Request a short-lived installation access token from GitHub.
 */
function getInstallationToken(string $jwt, string $installationId): string {
    $result = githubApi(
        'POST',
        '/app/installations/' . $installationId . '/access_tokens',
        null,
        $jwt
    );

    if (empty($result['token'])) {
        $errorMsg = $result['message'] ?? 'Unknown error';
        throw new RuntimeException('Failed to obtain installation token: ' . $errorMsg);
    }

    return $result['token'];
}

/**
 * Get a fresh installation token using the configured GitHub App credentials.
 */
function getGitHubToken(): string {
    $config = getConfig();
    $gh = $config['github'];

    if (empty($gh['app_id']) || empty($gh['installation_id'])) {
        throw new RuntimeException('GitHub App not configured');
    }

    $jwt = generateGitHubJWT($gh['app_id'], $gh['private_key_path']);
    return getInstallationToken($jwt, $gh['installation_id']);
}

// ── GitHub REST API Client ────────────────────────────────────────────────────

/**
 * Make a GitHub API request via curl.
 */
function githubApi(string $method, string $endpoint, ?array $body, string $token): array {
    $url = 'https://api.github.com' . $endpoint;

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: Framework-Manager',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException('GitHub API request failed: ' . $error);
    }

    $decoded = json_decode($response, true) ?? [];

    if ($httpCode >= 400) {
        $msg = $decoded['message'] ?? 'Unknown error';
        throw new RuntimeException("GitHub API error ($httpCode): $msg");
    }

    return $decoded;
}

// ── Git Data API Operations ───────────────────────────────────────────────────

/**
 * Get the SHA of a branch ref.
 * Returns null if the branch doesn't exist.
 */
function githubGetRef(string $token, string $owner, string $repo, string $branch): ?string {
    try {
        $result = githubApi('GET', "/repos/$owner/$repo/git/ref/heads/$branch", null, $token);
        return $result['object']['sha'] ?? null;
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '404')) return null;
        throw $e;
    }
}

/**
 * Create a new tree from an array of file entries.
 * Each item: ['path' => '...', 'content' => '...']
 * Items with 'content' => null are deletions (sha set to null).
 * Uses inline content to avoid separate blob creation.
 */
function githubCreateTree(string $token, string $owner, string $repo, ?string $baseTreeSha, array $files): string {
    $treeItems = [];
    foreach ($files as $file) {
        if ($file['content'] === null) {
            // Deletion entry: setting sha to null removes the file from the tree
            $treeItems[] = [
                'path'    => $file['path'],
                'mode'    => '100644',
                'type'    => 'blob',
                'sha'     => null,
            ];
        } else {
            $treeItems[] = [
                'path'    => $file['path'],
                'mode'    => '100644',
                'type'    => 'blob',
                'content' => $file['content'],
            ];
        }
    }

    $body = ['tree' => $treeItems];
    if ($baseTreeSha) {
        $body['base_tree'] = $baseTreeSha;
    }

    $result = githubApi('POST', "/repos/$owner/$repo/git/trees", $body, $token);

    if (empty($result['sha'])) {
        throw new RuntimeException('Failed to create tree');
    }
    return $result['sha'];
}

/**
 * Recursively list all file paths in a tree.
 */
function githubListTreeFiles(string $token, string $owner, string $repo, string $treeSha): array {
    $result = githubApi('GET', "/repos/$owner/$repo/git/trees/$treeSha?recursive=1", null, $token);
    $paths = [];
    foreach ($result['tree'] ?? [] as $item) {
        if (($item['type'] ?? '') === 'blob') {
            $paths[] = $item['path'];
        }
    }
    return $paths;
}

/**
 * Create a commit.
 */
function githubCreateCommit(string $token, string $owner, string $repo, string $message, string $treeSha, string $parentSha): string {
    $result = githubApi('POST', "/repos/$owner/$repo/git/commits", [
        'message' => $message,
        'tree'    => $treeSha,
        'parents' => [$parentSha],
    ], $token);

    if (empty($result['sha'])) {
        throw new RuntimeException('Failed to create commit');
    }
    return $result['sha'];
}

/**
 * Update (or force-update) a branch ref to point to a new commit.
 */
function githubUpdateRef(string $token, string $owner, string $repo, string $branch, string $sha, bool $force = false): void {
    githubApi('PATCH', "/repos/$owner/$repo/git/refs/heads/$branch", [
        'sha'   => $sha,
        'force' => $force,
    ], $token);
}

/**
 * Create a new branch ref.
 */
function githubCreateRef(string $token, string $owner, string $repo, string $branch, string $sha): void {
    githubApi('POST', "/repos/$owner/$repo/git/refs", [
        'ref' => 'refs/heads/' . $branch,
        'sha' => $sha,
    ], $token);
}

/**
 * Create a pull request. Returns ['html_url' => '...', 'number' => N].
 */
function githubCreatePR(string $token, string $owner, string $repo, string $title, string $body, string $head, string $base): array {
    return githubApi('POST', "/repos/$owner/$repo/pulls", [
        'title' => $title,
        'body'  => $body,
        'head'  => $head,
        'base'  => $base,
    ], $token);
}

/**
 * Find an existing open PR from head → base. Returns PR data or null.
 */
function githubFindExistingPR(string $token, string $owner, string $repo, string $head, string $base): ?array {
    try {
        $result = githubApi('GET', "/repos/$owner/$repo/pulls?head=$owner:$head&base=$base&state=open", null, $token);
        if (!empty($result[0])) {
            return $result[0];
        }
        return null;
    } catch (RuntimeException $e) {
        // Only swallow 404 (no matching PR); re-throw auth failures, network errors, etc.
        if (str_contains($e->getMessage(), '404')) {
            return null;
        }
        throw $e;
    }
}

/**
 * Get file content from a specific branch. Returns content string or null.
 */
function githubGetFileContent(string $token, string $owner, string $repo, string $path, string $branch): ?string {
    try {
        $result = githubApi('GET', "/repos/$owner/$repo/contents/$path?ref=$branch", null, $token);
        if (!empty($result['content'])) {
            return base64_decode($result['content']);
        }
        return null;
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), '404')) return null;
        throw $e;
    }
}

/**
 * Get the tree SHA for a commit.
 */
function githubGetCommitTree(string $token, string $owner, string $repo, string $commitSha): string {
    $result = githubApi('GET', "/repos/$owner/$repo/git/commits/$commitSha", null, $token);
    if (empty($result['tree']['sha'])) {
        throw new RuntimeException('Failed to get commit tree');
    }
    return $result['tree']['sha'];
}

// ── Publish Orchestrator ──────────────────────────────────────────────────────

/**
 * Publish YAML files + changelog to GitHub, open a PR.
 *
 * @param array  $yamlFiles     Output of frameworkToYamlFiles()
 * @param string $changelog     New changelog entry (Markdown)
 * @param string $version       Version string (e.g. "2.1.0")
 * @param string $commitMessage Commit message
 * @return array ['pr_url', 'pr_number', 'commit_sha']
 */
function publishToGitHub(string $changelog, string $version, string $commitMessage, array $fw = [], ?callable $progressFn = null): array {
    $config = getConfig();
    $gh = $config['github'];
    $owner = $gh['owner'];
    $repo  = $gh['repo'];
    $baseBranch = $gh['base_branch'];
    $devBranch  = $gh['dev_branch'];

    $progress = $progressFn ?? function(string $step) {};

    // 1. Authenticate
    $progress('github_auth');
    $token = getGitHubToken();

    // 2. Get base branch SHA
    try {
        $baseSha = githubGetRef($token, $owner, $repo, $baseBranch);
    } catch (RuntimeException $e) {
        throw new RuntimeException("Step 2 — get base branch '$baseBranch': " . $e->getMessage());
    }
    if (!$baseSha) {
        throw new RuntimeException("Base branch '$baseBranch' not found");
    }

    // 3. Get or create dev branch
    try {
        $devSha = githubGetRef($token, $owner, $repo, $devBranch);
        if (!$devSha) {
            githubCreateRef($token, $owner, $repo, $devBranch, $baseSha);
            $devSha = $baseSha;
        }
    } catch (RuntimeException $e) {
        throw new RuntimeException("Step 3 — create dev branch: " . $e->getMessage());
    }

    // 4. Build the file tree
    $progress('documentation');

    // Generate YAML files for both frameworks and merge
    $allFiles = [];
    foreach (frameworkSlugs() as $framework) {
        $fwYaml = frameworkToYamlFiles($fw, $framework);
        foreach ($fwYaml as $f) {
            $allFiles[] = $f;
        }
    }

    // Add documentation files for both frameworks
    foreach (frameworkSlugs() as $framework) {
        $docFiles = frameworkToDocFiles($fw, $framework, $owner, $repo);
        foreach ($docFiles as $doc) {
            $allFiles[] = $doc;
        }
    }

    // Add schema files
    $allFiles[] = ['path' => 'schema/tactic.schema.json',    'content' => getTacticSchema()];
    $allFiles[] = ['path' => 'schema/technique.schema.json', 'content' => getTechniqueSchema()];
    $allFiles[] = ['path' => 'schema/subtechnique.schema.json', 'content' => getSubTechniqueSchema()];

    // Add CI workflow files
    $allFiles[] = ['path' => '.github/workflows/validate.yml', 'content' => getValidateWorkflow()];
    $allFiles[] = ['path' => '.github/workflows/release.yml',  'content' => getReleaseWorkflow()];

    // Add translation files (if DeepL is configured)
    $progress('translations');
    $trConfig = $config['translations'] ?? [];
    $deeplKey = $trConfig['deepl_api_key'] ?? '';
    $langStr  = $trConfig['languages'] ?? '';
    if ($deeplKey && $langStr) {
        $targetLangs = array_filter(array_map('trim', explode(',', $langStr)));
        if ($targetLangs) {
            $translations = translateFramework($fw, $targetLangs, $deeplKey);
            foreach (frameworkSlugs() as $framework) {
                $prefix = frameworkToPrefix($framework);
                // Filter translations to only include IDs from this framework
                $filtered = [];
                foreach ($translations as $lang => $items) {
                    $filtered[$lang] = array_filter($items, fn($id) => str_starts_with($id, $prefix), ARRAY_FILTER_USE_KEY);
                }
                $translationFiles = translationsToYamlFiles($filtered, $framework);
                foreach ($translationFiles as $tf) {
                    $allFiles[] = $tf;
                }
            }
            $allFiles[] = ['path' => 'schema/translation.schema.json', 'content' => getTranslationSchema()];
        }
    }

    // Build CHANGELOG.md — prepend new entry to existing content
    $existingChangelog = githubGetFileContent($token, $owner, $repo, 'CHANGELOG.md', $devBranch);
    if ($existingChangelog) {
        // Insert after the first heading line (# Changelog)
        $parts = explode("\n", $existingChangelog, 2);
        if (str_starts_with(trim($parts[0] ?? ''), '#')) {
            $fullChangelog = $parts[0] . "\n\n" . $changelog . "\n" . ($parts[1] ?? '');
        } else {
            $fullChangelog = "# Changelog\n\n" . $changelog . "\n\n" . $existingChangelog;
        }
    } else {
        $fullChangelog = "# Changelog\n\n" . $changelog . "\n";
    }
    $allFiles[] = ['path' => 'CHANGELOG.md', 'content' => $fullChangelog];

    // 4b. Ensure workflow files exist on the base branch so PR triggers fire
    $workflowFiles = array_filter($allFiles, fn($f) => str_starts_with($f['path'], '.github/workflows/'));
    if ($workflowFiles) {
        try {
            $needsUpdate = false;
            foreach ($workflowFiles as $wf) {
                $existing = githubGetFileContent($token, $owner, $repo, $wf['path'], $baseBranch);
                if ($existing !== $wf['content']) {
                    $needsUpdate = true;
                    break;
                }
            }
            if ($needsUpdate) {
                $baseTreeSha = githubGetCommitTree($token, $owner, $repo, $baseSha);
                $wfTreeSha = githubCreateTree($token, $owner, $repo, $baseTreeSha, array_values($workflowFiles));
                $wfCommitSha = githubCreateCommit($token, $owner, $repo, 'chore: update CI workflow files', $wfTreeSha, $baseSha);
                githubUpdateRef($token, $owner, $repo, $baseBranch, $wfCommitSha, false);
                // Re-read base SHA after the workflow commit
                $baseSha = $wfCommitSha;
                // Also update dev branch to include the new base
                $devSha = $baseSha;
                githubUpdateRef($token, $owner, $repo, $devBranch, $devSha, true);
            }
        } catch (RuntimeException $e) {
            // Non-fatal: workflows may not trigger on first PR, but publish still works
            error_log("Warning: could not push workflow files to $baseBranch: " . $e->getMessage());
        }
    }

    // 5. Create tree (with stale file cleanup)
    $progress('push');
    try {
        $devTreeSha = githubGetCommitTree($token, $owner, $repo, $devSha);

        // Detect stale files in managed directories and add deletion entries.
        // Managed prefixes: framework YAML, docs, translations, schemas, workflows.
        $managedPrefixes = array_merge(array_map(fn($s) => $s . '/', frameworkSlugs()), ['schema/', '.github/workflows/']);
        $newPaths = array_flip(array_column($allFiles, 'path'));
        $existingPaths = githubListTreeFiles($token, $owner, $repo, $devTreeSha);
        foreach ($existingPaths as $existingPath) {
            if (isset($newPaths[$existingPath])) continue; // will be overwritten
            foreach ($managedPrefixes as $prefix) {
                if (str_starts_with($existingPath, $prefix)) {
                    // Stale file — add deletion entry
                    $allFiles[] = ['path' => $existingPath, 'content' => null];
                    break;
                }
            }
        }

        $treeSha = githubCreateTree($token, $owner, $repo, $devTreeSha, $allFiles);
    } catch (RuntimeException $e) {
        throw new RuntimeException("Step 5 — create tree (" . count($allFiles) . " files): " . $e->getMessage());
    }

    // 6. Create commit
    try {
        $commitSha = githubCreateCommit($token, $owner, $repo, $commitMessage, $treeSha, $devSha);
    } catch (RuntimeException $e) {
        throw new RuntimeException("Step 6 — create commit: " . $e->getMessage());
    }

    // 7. Update dev branch
    try {
        githubUpdateRef($token, $owner, $repo, $devBranch, $commitSha, true);
    } catch (RuntimeException $e) {
        throw new RuntimeException("Step 7 — update dev branch: " . $e->getMessage());
    }

    // 8. Create PR (or find existing one from dev → main)
    $prTitle = "Release v$version";
    $fwName = profileValue('framework_name', 'Framework');
    $productName = profileValue('product_name', 'Framework Manager');
    $prBody = "## $fwName Framework v$version\n\n"
        . "This PR was generated by the $productName.\n\n"
        . "### Changes\n\n" . $changelog;

    try {
        $pr = githubCreatePR($token, $owner, $repo, $prTitle, $prBody, $devBranch, $baseBranch);
    } catch (RuntimeException $e) {
        // If a PR already exists from dev → main, find and reuse it
        if (str_contains($e->getMessage(), '422')) {
            $pr = githubFindExistingPR($token, $owner, $repo, $devBranch, $baseBranch);
            if (!$pr) {
                throw new RuntimeException("Step 8 — create pull request: " . $e->getMessage());
            }
        } else {
            throw new RuntimeException("Step 8 — create pull request: " . $e->getMessage());
        }
    }

    return [
        'pr_url'     => $pr['html_url'] ?? '',
        'pr_number'  => $pr['number'] ?? 0,
        'commit_sha' => $commitSha,
    ];
}

// ── Changelog Generator ──────────────────────────────────────────────────────

/**
 * Escape Markdown special characters in user-supplied text.
 */
function escapeMarkdown(string $text): string {
    return str_replace(
        ['\\',  '|',  '*',  '_',  '[',  ']',  '`'],
        ['\\\\', '\\|', '\\*', '\\_', '\\[', '\\]', '\\`'],
        $text
    );
}

/**
 * Generate a Markdown changelog entry from approved, unpublished changes.
 */
function generateChangelog(array $changes, string $version): string {
    $added      = [];
    $changed    = [];
    $deprecated = [];

    foreach ($changes as $c) {
        $id   = $c['framework_id'] ?? '';
        $rawName = $c['after']['name'] ?? $c['before']['name'] ?? '';
        $rawDesc = $c['description'] ?? '';
        $framework = $c['framework'] ?? '';
        $name = escapeMarkdown($rawName);
        $desc = escapeMarkdown($rawDesc);
        $line = $id ? "**$id** $name" : $name;
        if ($framework) $line .= " [$framework]";
        if ($desc) $line .= " — $desc";

        switch ($c['type'] ?? '') {
            case 'add':
                $added[] = $line;
                break;
            case 'edit':
                $changed[] = $line;
                break;
            case 'delete':
                $deprecated[] = $line;
                break;
        }
    }

    $date = date('Y-m-d');
    $md = "## v$version — $date\n";

    if (!empty($added)) {
        $md .= "\n### Added\n\n";
        foreach ($added as $item) $md .= "- $item\n";
    }
    if (!empty($changed)) {
        $md .= "\n### Changed\n\n";
        foreach ($changed as $item) $md .= "- $item\n";
    }
    if (!empty($deprecated)) {
        $md .= "\n### Deprecated\n\n";
        foreach ($deprecated as $item) $md .= "- $item\n";
    }

    if (empty($added) && empty($changed) && empty($deprecated)) {
        $md .= "\nNo tracked changes.\n";
    }

    return $md;
}

// ── JSON Schema Generators ───────────────────────────────────────────────────

/**
 * Common profile-derived building blocks for the published JSON schemas.
 */
function _schemaCommon(): array {
    $prefixes = array_values(array_map(fn($s) => subframeworkConf($s)['id_prefix'], frameworkSlugs()));
    return [
        'base'   => rtrim(profileValue('base_url', ''), '/'),
        'fwName' => profileValue('framework_name', 'Framework'),
        'slugs'  => frameworkSlugs(),
        'pfx'    => implode('|', $prefixes),      // e.g. "OBS|ASM"
        'ex1'    => $prefixes[0] ?? 'XXX',
        'ex2'    => $prefixes[1] ?? ($prefixes[0] ?? 'YYY'),
    ];
}

function getTacticSchema(): string {
    $c = _schemaCommon();
    $pTactic = '^(' . $c['pfx'] . ')\d{3}$';
    return json_encode([
        '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
        '$id'         => $c['base'] . '/schema/tactic.schema.json',
        'title'       => $c['fwName'] . ' Tactic',
        'description' => 'A ' . $c['fwName'] . ' framework tactic definition.',
        'type'        => 'object',
        'required'    => ['id', 'name', 'description', 'shortname', 'framework'],
        'properties'  => [
            'id' => [
                'type'        => 'string',
                'pattern'     => $pTactic,
                'description' => "Tactic ID (e.g. {$c['ex1']}001, {$c['ex2']}001)",
            ],
            'name' => [
                'type'        => 'string',
                'minLength'   => 1,
                'description' => 'Display name of the tactic',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Detailed description of the tactic',
            ],
            'shortname' => [
                'type'        => 'string',
                'pattern'     => '^[a-z][a-z0-9,.-]*$',
                'description' => 'Slug used in kill chain references',
            ],
            'created' => [
                'type'        => 'string',
                'description' => 'ISO 8601 creation timestamp',
            ],
            'modified' => [
                'type'        => 'string',
                'description' => 'ISO 8601 last-modified timestamp',
            ],
            'deprecated' => [
                'type'        => 'boolean',
                'description' => 'Whether this tactic has been deprecated',
            ],
            'replaced_by' => [
                'type'        => 'string',
                'pattern'     => $pTactic,
                'description' => 'ID of the tactic that replaces this one',
            ],
            'framework' => [
                'type'        => 'string',
                'enum'        => $c['slugs'],
                'description' => 'Which framework this tactic belongs to',
            ],
        ],
        'additionalProperties' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function getTechniqueSchema(): string {
    $c = _schemaCommon();
    $pTech = '^(' . $c['pfx'] . ')\d{3}\.\d{3}$';
    $pRel  = '^(' . $c['pfx'] . ')\d{3}(\.\d{3}){1,2}$';
    return json_encode([
        '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
        '$id'         => $c['base'] . '/schema/technique.schema.json',
        'title'       => $c['fwName'] . ' Technique',
        'description' => 'A ' . $c['fwName'] . ' framework technique definition.',
        'type'        => 'object',
        'required'    => ['id', 'name', 'description', 'framework'],
        'properties'  => [
            'id' => [
                'type'        => 'string',
                'pattern'     => $pTech,
                'description' => "Technique ID (e.g. {$c['ex1']}001.001, {$c['ex2']}001.001)",
            ],
            'name' => [
                'type'        => 'string',
                'minLength'   => 1,
                'description' => 'Display name of the technique',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Detailed description of the technique',
            ],
            'created' => [
                'type'        => 'string',
                'description' => 'ISO 8601 creation timestamp',
            ],
            'modified' => [
                'type'        => 'string',
                'description' => 'ISO 8601 last-modified timestamp',
            ],
            'tactics' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'List of tactic shortnames this technique belongs to',
            ],
            'platforms' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Target platforms',
            ],
            'reports' => [
                'type'        => 'array',
                'items'       => [
                    'type'       => 'object',
                    'required'   => ['url'],
                    'properties' => [
                        'url'     => [
                            'type'        => 'string',
                            'format'      => 'uri',
                            'description' => 'URL of the related report',
                        ],
                        'excerpt' => [
                            'type'        => 'string',
                            'description' => 'Relevant excerpt from the report',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'description' => 'Related reports with URLs and excerpts',
            ],
            'related_techniques' => [
                'type'        => 'array',
                'items'       => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'    => 'string',
                            'pattern' => $pRel,
                            'description' => $c['fwName'] . ' ID of the related technique or sub-technique',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Why these techniques are related',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'description' => 'Related techniques with optional descriptions',
            ],
            'deprecated' => [
                'type'        => 'boolean',
                'description' => 'Whether this technique has been deprecated',
            ],
            'replaced_by' => [
                'type'        => 'string',
                'pattern'     => $pRel,
                'description' => 'ID of the technique or sub-technique that replaces this one',
            ],
            'framework' => [
                'type'        => 'string',
                'enum'        => $c['slugs'],
                'description' => 'Which framework this technique belongs to',
            ],
        ],
        'additionalProperties' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function getSubTechniqueSchema(): string {
    $c = _schemaCommon();
    $pSub    = '^(' . $c['pfx'] . ')\d{3}\.\d{3}\.\d{3}$';
    $pParent = '^(' . $c['pfx'] . ')\d{3}\.\d{3}$';
    $pRel    = '^(' . $c['pfx'] . ')\d{3}(\.\d{3}){1,2}$';
    return json_encode([
        '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
        '$id'         => $c['base'] . '/schema/subtechnique.schema.json',
        'title'       => $c['fwName'] . ' Sub-Technique',
        'description' => 'A ' . $c['fwName'] . ' framework sub-technique definition.',
        'type'        => 'object',
        'required'    => ['id', 'name', 'description', 'framework', 'parent_technique', 'is_subtechnique'],
        'properties'  => [
            'id' => [
                'type'        => 'string',
                'pattern'     => $pSub,
                'description' => "Sub-technique ID (e.g. {$c['ex1']}001.001.001, {$c['ex2']}001.001.001)",
            ],
            'name' => [
                'type'        => 'string',
                'minLength'   => 1,
                'description' => 'Display name of the sub-technique',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'Detailed description of the sub-technique',
            ],
            'parent_technique' => [
                'type'        => 'string',
                'pattern'     => $pParent,
                'description' => $c['fwName'] . " ID of the parent technique (e.g. {$c['ex1']}001.001)",
            ],
            'is_subtechnique' => [
                'type'        => 'boolean',
                'const'       => true,
                'description' => 'Always true for sub-technique objects',
            ],
            'created' => [
                'type'        => 'string',
                'description' => 'ISO 8601 creation timestamp',
            ],
            'modified' => [
                'type'        => 'string',
                'description' => 'ISO 8601 last-modified timestamp',
            ],
            'tactics' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'List of tactic shortnames this sub-technique belongs to',
            ],
            'platforms' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Target platforms',
            ],
            'reports' => [
                'type'        => 'array',
                'items'       => [
                    'type'       => 'object',
                    'required'   => ['url'],
                    'properties' => [
                        'url'     => [
                            'type'        => 'string',
                            'format'      => 'uri',
                            'description' => 'URL of the related report',
                        ],
                        'excerpt' => [
                            'type'        => 'string',
                            'description' => 'Relevant excerpt from the report',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'description' => 'Related reports with URLs and excerpts',
            ],
            'related_techniques' => [
                'type'        => 'array',
                'items'       => [
                    'type'       => 'object',
                    'required'   => ['id'],
                    'properties' => [
                        'id' => [
                            'type'    => 'string',
                            'pattern' => $pRel,
                            'description' => $c['fwName'] . ' ID of the related technique or sub-technique',
                        ],
                        'description' => [
                            'type'        => 'string',
                            'description' => 'Why these techniques are related',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'description' => 'Related techniques with optional descriptions',
            ],
            'deprecated' => [
                'type'        => 'boolean',
                'description' => 'Whether this sub-technique has been deprecated',
            ],
            'replaced_by' => [
                'type'        => 'string',
                'pattern'     => $pRel,
                'description' => 'ID of the technique or sub-technique that replaces this one',
            ],
            'framework' => [
                'type'        => 'string',
                'enum'        => $c['slugs'],
                'description' => 'Which framework this sub-technique belongs to',
            ],
        ],
        'additionalProperties' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function getTranslationSchema(): string {
    $c = _schemaCommon();
    return json_encode([
        '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
        'title'      => $c['fwName'] . ' Translation',
        'type'       => 'object',
        'required'   => ['id', 'name', 'description', 'framework'],
        'properties' => [
            'id'          => ['type' => 'string', 'pattern' => '^(' . $c['pfx'] . ')\d{3}(\.\d{3}){1,2}$'],
            'name'        => ['type' => 'string', 'minLength' => 1],
            'description' => ['type' => 'string'],
            'framework'   => ['type' => 'string', 'enum' => $c['slugs']],
        ],
        'additionalProperties' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

// ── CI Workflow Generators ───────────────────────────────────────────────────

function getValidateWorkflow(): string {
    $yaml = <<<'YAML'
name: Validate Framework Objects

on:
  pull_request:
    paths:
      - '**/objects/**/*.yaml'
      - '*/translations/**/*.yaml'
      - 'schema/*.json'

jobs:
  validate:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5

      - name: Set up Python
        uses: actions/setup-python@v6
        with:
          python-version: '3.12'

      - name: Install dependencies
        run: |
          pip install pyyaml jsonschema check-jsonschema

      - name: Validate YAML syntax (objects)
        run: |
          for fw in @@FW_SLUGS_SH@@; do
            if [ -d "$fw/objects" ]; then
              find "$fw/objects/" -name '*.yaml' -exec python -c "
          import sys, yaml
          for f in sys.argv[1:]:
              try:
                  yaml.safe_load(open(f))
              except Exception as e:
                  print(f'FAIL: {f}: {e}')
                  sys.exit(1)
          " {} +
            fi
          done

      - name: Validate tactics against schema
        run: |
          for fw in @@FW_SLUGS_SH@@; do
            if [ -d "$fw/objects/tactics" ]; then
              for f in "$fw"/objects/tactics/*.yaml; do
                [ -f "$f" ] || continue
                check-jsonschema --schemafile schema/tactic.schema.json "$f"
              done
            fi
          done

      - name: Validate techniques against schema
        run: |
          for fw in @@FW_SLUGS_SH@@; do
            if [ -d "$fw/objects/techniques" ]; then
              for f in "$fw"/objects/techniques/*.yaml; do
                [ -f "$f" ] || continue
                check-jsonschema --schemafile schema/technique.schema.json "$f"
              done
            fi
          done

      - name: Validate sub-techniques against schema
        run: |
          for fw in @@FW_SLUGS_SH@@; do
            if [ -d "$fw/objects/subtechniques" ]; then
              for f in "$fw"/objects/subtechniques/*.yaml; do
                [ -f "$f" ] || continue
                check-jsonschema --schemafile schema/subtechnique.schema.json "$f"
              done
            fi
          done

      - name: Validate translations against schema
        run: |
          if [ -f schema/translation.schema.json ]; then
            for fw in @@FW_SLUGS_SH@@; do
              if [ -d "$fw/translations" ]; then
                find "$fw/translations/" -name '*.yaml' -exec check-jsonschema --schemafile schema/translation.schema.json {} +
              fi
            done
          fi

      - name: Check for duplicate IDs
        run: |
          python - <<'PYEOF'
          import os, yaml, sys
          ids = {}
          for fw in @@FW_SLUGS@@:
              obj_dir = os.path.join(fw, 'objects')
              if not os.path.isdir(obj_dir): continue
              for root, dirs, files in os.walk(obj_dir):
                  for f in files:
                      if not f.endswith('.yaml'): continue
                      path = os.path.join(root, f)
                      data = yaml.safe_load(open(path))
                      obj_id = data.get('id', '')
                      if obj_id in ids:
                          print(f'DUPLICATE: {obj_id} in {path} and {ids[obj_id]}')
                          sys.exit(1)
                      ids[obj_id] = path
          print(f'OK: {len(ids)} unique IDs')
          PYEOF

      - name: Check cross-references
        run: |
          python - <<'PYEOF'
          import os, yaml, sys
          tactics, techniques, subtechniques, tech_data, subtech_data, errors = set(), set(), set(), [], [], []
          for fw in @@FW_SLUGS@@:
              tac_dir = os.path.join(fw, 'objects', 'tactics')
              if os.path.isdir(tac_dir):
                  for f in os.listdir(tac_dir):
                      if not f.endswith('.yaml'): continue
                      data = yaml.safe_load(open(os.path.join(tac_dir, f)))
                      if data.get('shortname'): tactics.add(data['shortname'])
              tech_dir = os.path.join(fw, 'objects', 'techniques')
              if os.path.isdir(tech_dir):
                  for f in sorted(os.listdir(tech_dir)):
                      if not f.endswith('.yaml'): continue
                      path = os.path.join(tech_dir, f)
                      data = yaml.safe_load(open(path))
                      techniques.add(data.get('id', ''))
                      tech_data.append((path, data))
              subtech_dir = os.path.join(fw, 'objects', 'subtechniques')
              if os.path.isdir(subtech_dir):
                  for f in sorted(os.listdir(subtech_dir)):
                      if not f.endswith('.yaml'): continue
                      path = os.path.join(subtech_dir, f)
                      data = yaml.safe_load(open(path))
                      subtechniques.add(data.get('id', ''))
                      subtech_data.append((path, data))
          all_ids = techniques | subtechniques
          # Check technique references
          for path, data in tech_data:
              for t in data.get('tactics', []):
                  if t not in tactics:
                      errors.append(f'{path}: tactic "{t}" not found')
              for rt in data.get('related_techniques', []):
                  rt_id = rt['id']
                  if rt_id not in all_ids:
                      errors.append(f'{path}: related_technique "{rt_id}" not found')
          # Check sub-technique references
          for path, data in subtech_data:
              parent = data.get('parent_technique', '')
              if parent not in techniques:
                  errors.append(f'{path}: parent_technique "{parent}" not found')
              for t in data.get('tactics', []):
                  if t not in tactics:
                      errors.append(f'{path}: tactic "{t}" not found')
              for rt in data.get('related_techniques', []):
                  rt_id = rt['id']
                  if rt_id not in all_ids:
                      errors.append(f'{path}: related_technique "{rt_id}" not found')
          if errors:
              for e in errors: print(f'ERROR: {e}')
              sys.exit(1)
          print(f'OK: all references resolve ({len(techniques)} techniques, {len(subtechniques)} sub-techniques, {len(tactics)} tactics)')
          PYEOF
YAML;
    return applyProfileToWorkflow($yaml);
}

/**
 * Substitute profile placeholders (@@...@@) baked into the release workflow's
 * embedded Python so the generated STIX/CI is framework-specific.
 */
function applyProfileToWorkflow(string $yaml): string {
    $fwName  = profileValue('framework_name', 'Framework');
    $propFw  = stixProp('framework');
    $propVer = stixProp('version');
    $extId   = profileValue('extension_definition_id');
    $base    = rtrim(profileValue('base_url', ''), '/');
    $map = [
        '@@SRC@@'         => profileValue('source_name', 'FRAMEWORK'),
        '@@ORG_NAME@@'    => profileValue('org_name', 'The Foundation'),
        '@@ORG_DESC@@'    => profileValue('org_description', ''),
        '@@EXT_NAME@@'    => profileValue('extension_definition_name', 'Framework Membership'),
        '@@EXT_DESC@@'    => "Adds $propFw to indicate which $fwName sub-framework an object belongs to, and $propVer on grouping objects to record the framework version.",
        '@@EXT_SCHEMA@@'  => "$base/stix/$extId.json",
        '@@EXT_ID@@'      => $extId,
        '@@IDENTITY_ID@@' => profileValue('identity_ref'),
        '@@PROP_FW@@'     => $propFw,
        '@@PROP_VER@@'    => $propVer,
        '@@FW_NAME@@'     => $fwName,
        '@@ARTIFACT@@'    => profileValue('artifact_slug', 'framework'),
    ];
    // Dynamic sub-framework lists for the embedded Python/shell.
    $slugs = frameworkSlugs();
    $kcMap = [];
    foreach ($slugs as $slug) {
        $kc = subframeworkConf($slug)['kill_chain_name'];
        $kcMap[$slug] = $kc;
        $map["@@KC_{$slug}@@"] = $kc;
    }
    $map['@@FW_SLUGS@@']    = json_encode(array_values($slugs));   // Python list literal
    $map['@@FW_SLUGS_SH@@'] = implode(' ', $slugs);                // shell word list
    $map['@@KC_MAP@@']      = json_encode($kcMap);                 // Python dict literal
    return strtr($yaml, $map);
}

function getReleaseWorkflow(): string {
    $yaml = <<<'YAML'
name: Build Release Artefacts

on:
  release:
    types: [published]

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5

      - name: Set up Python
        uses: actions/setup-python@v6
        with:
          python-version: '3.12'

      - name: Install dependencies
        run: pip install pyyaml

      - name: Get version from tag
        id: version
        run: |
          TAG="${{ github.event.release.tag_name }}"
          echo "TAG=$TAG" >> "$GITHUB_OUTPUT"
          echo "VERSION=${TAG#v}" >> "$GITHUB_OUTPUT"  # strips leading 'v' if present

      - name: Build STIX 2.1 bundle
        run: |
          python - <<'PYEOF'
          import os, yaml, json, uuid, re
          from datetime import datetime, timezone

          repo_full = "${{ github.repository }}"  # e.g. "owner/repo"

          def sanitize_phase_name(s):
              return re.sub(r'[^a-z0-9-]', '', s)

          def normalize_ts(ts):
              if not ts: return None
              return re.sub(r'\+00:?00$', 'Z', ts)

          def doc_url(framework, obj_type, framework_id):
              return f"https://github.com/{repo_full}/blob/main/{framework}/documentation/{obj_type}/{framework_id}.md"

          bundle = {
              "type": "bundle",
              "id": f"bundle--{uuid.uuid4()}",
              "spec_version": "2.1",
              "objects": []
          }

          marking = "marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31"
          now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000Z")

          # Include the organisation identity
          identity_id = "@@IDENTITY_ID@@"
          bundle["objects"].append({
              "type": "identity",
              "spec_version": "2.1",
              "id": identity_id,
              "created": "2024-01-01T00:00:00.000Z",
              "modified": "2024-01-01T00:00:00.000Z",
              "name": "@@ORG_NAME@@",
              "description": "@@ORG_DESC@@",
              "identity_class": "organization"
          })

          # Include the CC-BY-SA marking definition referenced by all objects
          bundle["objects"].append({
              "type": "marking-definition",
              "spec_version": "2.1",
              "id": marking,
              "created": "2024-01-01T00:00:00.000Z",
              "definition_type": "statement",
              "name": "CC-BY-SA-4.0",
              "definition": {"statement": "CC-BY-SA-4.0 @@ORG_NAME@@"},
              "created_by_ref": identity_id
          })

          # STIX 2.1 extension-definition for the framework-membership custom property
          bundle["objects"].append({
              "type": "extension-definition",
              "spec_version": "2.1",
              "id": "@@EXT_ID@@",
              "created": "2026-03-30T00:00:00.000Z",
              "modified": "2026-03-30T00:00:00.000Z",
              "name": "@@EXT_NAME@@",
              "description": "@@EXT_DESC@@",
              "schema": "@@EXT_SCHEMA@@",
              "version": "1.0",
              "extension_types": ["property-extension"],
              "created_by_ref": "@@IDENTITY_ID@@"
          })

          # Framework → kill_chain_name mapping
          FRAMEWORK_KILL_CHAIN = @@KC_MAP@@

          def get_kill_chain(data):
              fw = data.get("framework", "")
              return FRAMEWORK_KILL_CHAIN.get(fw, fw)

          # Load tactics and techniques from both frameworks
          tactics_by_shortname = {}
          for fw_name in @@FW_SLUGS@@:
              tac_dir = os.path.join(fw_name, "objects", "tactics")
              if not os.path.isdir(tac_dir): continue
              for f in sorted(os.listdir(tac_dir)):
                  if not f.endswith('.yaml'): continue
                  data = yaml.safe_load(open(os.path.join(tac_dir, f)))
                  shortname = sanitize_phase_name(data['shortname'])
                  tactics_by_shortname[shortname] = data
                  kill_chain = get_kill_chain(data)
                  obj = {
                      "type": "x-mitre-tactic",
                      "spec_version": "2.1",
                      "id": f"x-mitre-tactic--{uuid.uuid4()}",
                      "created": normalize_ts(data.get("created")) or now,
                      "modified": normalize_ts(data.get("modified")) or now,
                      "name": data["name"],
                      "description": data.get("description", ""),
                      "x_mitre_shortname": shortname,
                      "@@PROP_FW@@": data.get("framework", ""),
                      "external_references": [{"source_name": "@@SRC@@", "external_id": data["id"], "url": doc_url(fw_name, "tactics", data["id"])}],
                      "object_marking_refs": [marking]
                  }
                  if data.get("deprecated"):
                      obj["x_mitre_deprecated"] = True
                      obj["revoked"] = True
                  bundle["objects"].append(obj)

          # Load techniques from both frameworks
          technique_files = []  # (framework_dir, filename)
          for fw_name in @@FW_SLUGS@@:
              tech_dir = os.path.join(fw_name, "objects", "techniques")
              if not os.path.isdir(tech_dir): continue
              for f in sorted(os.listdir(tech_dir)):
                  if not f.endswith('.yaml'): continue
                  technique_files.append((tech_dir, f))
          # Tactics (no dot) before techniques (with dot)
          technique_files.sort(key=lambda x: (1 if '.' in x[1].replace('.yaml','') else 0, x[1]))

          technique_ids = {}  # framework_id -> stix_id
          related_pairs = []  # collect (source_framework_id, target_framework_id) for deferred relationship creation
          for tech_dir, f in technique_files:
              data = yaml.safe_load(open(os.path.join(tech_dir, f)))
              stix_id = f"attack-pattern--{uuid.uuid4()}"
              technique_ids[data["id"]] = stix_id
              kill_chain = get_kill_chain(data)
              tech_fw = data.get("framework", tech_dir.split(os.sep)[0])
              ext_refs = [{"source_name": "@@SRC@@", "external_id": data["id"], "url": doc_url(tech_fw, "techniques", data["id"])}]
              for report in data.get("reports", []):
                  ref = {"source_name": "report", "url": report["url"]}
                  if report.get("excerpt"):
                      ref["description"] = report["excerpt"]
                  ext_refs.append(ref)
              obj = {
                  "type": "attack-pattern",
                  "spec_version": "2.1",
                  "id": stix_id,
                  "created": normalize_ts(data.get("created")) or now,
                  "modified": normalize_ts(data.get("modified")) or now,
                  "name": data["name"],
                  "description": data.get("description", ""),
                  "@@PROP_FW@@": data.get("framework", ""),
                  "kill_chain_phases": [
                      {"kill_chain_name": kill_chain, "phase_name": sanitize_phase_name(t)}
                      for t in data.get("tactics", [])
                  ],
                  "x_mitre_platforms": data.get("platforms", []),
                  "external_references": ext_refs,
                  "object_marking_refs": [marking]
              }
              if data.get("deprecated"):
                  obj["x_mitre_deprecated"] = True
                  obj["revoked"] = True
              bundle["objects"].append(obj)

              for rt in data.get("related_techniques", []):
                  rt_id = rt["id"]
                  rt_desc = rt.get("description", "")
                  pair = tuple(sorted([data["id"], rt_id]))
                  if pair not in related_pairs:
                      related_pairs.append((pair, rt_desc))

          # Load sub-techniques from both frameworks
          subtechnique_of_rels = []  # (sub_framework_id, parent_framework_id)
          for fw_name in @@FW_SLUGS@@:
              subtech_dir = os.path.join(fw_name, "objects", "subtechniques")
              if not os.path.isdir(subtech_dir): continue
              for f in sorted(os.listdir(subtech_dir)):
                  if not f.endswith('.yaml'): continue
                  data = yaml.safe_load(open(os.path.join(subtech_dir, f)))
                  stix_id = f"attack-pattern--{uuid.uuid4()}"
                  technique_ids[data["id"]] = stix_id
                  kill_chain = get_kill_chain(data)
                  sub_fw = data.get("framework", fw_name)
                  ext_refs = [{"source_name": "@@SRC@@", "external_id": data["id"], "url": doc_url(sub_fw, "subtechniques", data["id"])}]
                  for report in data.get("reports", []):
                      ref = {"source_name": "report", "url": report["url"]}
                      if report.get("excerpt"):
                          ref["description"] = report["excerpt"]
                      ext_refs.append(ref)
                  obj = {
                      "type": "attack-pattern",
                      "spec_version": "2.1",
                      "id": stix_id,
                      "created": normalize_ts(data.get("created")) or now,
                      "modified": normalize_ts(data.get("modified")) or now,
                      "name": data["name"],
                      "description": data.get("description", ""),
                      "@@PROP_FW@@": data.get("framework", ""),
                      "x_mitre_is_subtechnique": True,
                      "kill_chain_phases": [
                          {"kill_chain_name": kill_chain, "phase_name": sanitize_phase_name(t)}
                          for t in data.get("tactics", [])
                      ],
                      "x_mitre_platforms": data.get("platforms", []),
                      "external_references": ext_refs,
                      "object_marking_refs": [marking]
                  }
                  if data.get("deprecated"):
                      obj["x_mitre_deprecated"] = True
                      obj["revoked"] = True
                  bundle["objects"].append(obj)

                  # Track subtechnique-of relationship
                  parent_id = data.get("parent_technique", "")
                  if parent_id:
                      subtechnique_of_rels.append((data["id"], parent_id))

                  for rt in data.get("related_techniques", []):
                      rt_id = rt["id"]
                      rt_desc = rt.get("description", "")
                      pair = tuple(sorted([data["id"], rt_id]))
                      if pair not in related_pairs:
                          related_pairs.append((pair, rt_desc))

          # Create related-to relationship objects (one per pair)
          for (src_id, tgt_id), desc in related_pairs:
              src_stix = technique_ids.get(src_id)
              tgt_stix = technique_ids.get(tgt_id)
              if src_stix and tgt_stix:
                  rel = {
                      "type": "relationship",
                      "spec_version": "2.1",
                      "id": f"relationship--{uuid.uuid4()}",
                      "created": now,
                      "modified": now,
                      "relationship_type": "related-to",
                      "source_ref": src_stix,
                      "target_ref": tgt_stix,
                      "object_marking_refs": [marking]
                  }
                  if desc:
                      rel["description"] = desc
                  bundle["objects"].append(rel)

          # Create subtechnique-of relationship objects
          for sub_id, parent_id in subtechnique_of_rels:
              sub_stix = technique_ids.get(sub_id)
              parent_stix = technique_ids.get(parent_id)
              if sub_stix and parent_stix:
                  bundle["objects"].append({
                      "type": "relationship",
                      "spec_version": "2.1",
                      "id": f"relationship--{uuid.uuid4()}",
                      "created": now,
                      "modified": now,
                      "relationship_type": "subtechnique-of",
                      "source_ref": sub_stix,
                      "target_ref": parent_stix,
                      "object_marking_refs": [marking]
                  })

          # Add grouping objects for each framework
          version = "${{ steps.version.outputs.VERSION }}"
          ext_def_id = "@@EXT_ID@@"
          ext_ref = {ext_def_id: {"extension_type": "property-extension"}}
          for fw_name, kill_chain in FRAMEWORK_KILL_CHAIN.items():
              bundle["objects"].append({
                  "type": "grouping",
                  "spec_version": "2.1",
                  "id": f"grouping--{uuid.uuid4()}",
                  "created": now,
                  "modified": now,
                  "name": f"@@FW_NAME@@ {fw_name.title()} Framework",
                  "@@PROP_VER@@": version,
                  "description": f"All objects belonging to the @@FW_NAME@@ {fw_name} framework (kill_chain_name: {kill_chain}).",
                  "context": "suspicious-activity",
                  "extensions": ext_ref,
                  "object_refs": [
                      o["id"] for o in bundle["objects"]
                      if o.get("@@PROP_FW@@") == fw_name
                  ],
                  "object_marking_refs": [marking]
              })
          with open(f"@@ARTIFACT@@-v{version}.stix.json", "w") as fp:
              json.dump(bundle, fp, indent=2)

          # Flat JSON export
          flat = {"tactics": [], "techniques": [], "subtechniques": []}
          for obj in bundle["objects"]:
              if obj["type"] == "x-mitre-tactic":
                  flat["tactics"].append({
                      "id": obj["external_references"][0]["external_id"],
                      "name": obj["name"],
                      "description": obj.get("description", ""),
                      "shortname": obj.get("x_mitre_shortname", ""),
                      "framework": obj.get("@@PROP_FW@@", ""),
                  })
              elif obj["type"] == "attack-pattern":
                  entry = {
                      "id": obj["external_references"][0]["external_id"],
                      "name": obj["name"],
                      "description": obj.get("description", ""),
                      "tactics": [p["phase_name"] for p in obj.get("kill_chain_phases", [])],
                      "platforms": obj.get("x_mitre_platforms", []),
                      "framework": obj.get("@@PROP_FW@@", ""),
                  }
                  if obj.get("x_mitre_is_subtechnique"):
                      flat["subtechniques"].append(entry)
                  else:
                      flat["techniques"].append(entry)
          with open(f"@@ARTIFACT@@-v{version}.json", "w") as fp:
              json.dump(flat, fp, indent=2)
          PYEOF

      - name: Build CSV export
        run: |
          python - <<'PYEOF'
          import os, yaml, csv, zipfile

          version = "${{ steps.version.outputs.VERSION }}"

          with open('tactics.csv', 'w', newline='') as fp:
              w = csv.writer(fp)
              w.writerow(['id', 'name', 'description', 'shortname', 'framework', 'deprecated'])
              for fw_name in @@FW_SLUGS@@:
                  tac_dir = os.path.join(fw_name, 'objects', 'tactics')
                  if not os.path.isdir(tac_dir): continue
                  for f in sorted(os.listdir(tac_dir)):
                      if not f.endswith('.yaml'): continue
                      d = yaml.safe_load(open(os.path.join(tac_dir, f)))
                      w.writerow([d['id'], d['name'], d.get('description',''), d['shortname'],
                                  d.get('framework',''), d.get('deprecated', False)])

          with open('techniques.csv', 'w', newline='') as fp:
              w = csv.writer(fp)
              w.writerow(['id', 'name', 'description', 'tactics', 'platforms', 'framework', 'deprecated'])
              for fw_name in @@FW_SLUGS@@:
                  tech_dir = os.path.join(fw_name, 'objects', 'techniques')
                  if not os.path.isdir(tech_dir): continue
                  for f in sorted(os.listdir(tech_dir)):
                      if not f.endswith('.yaml'): continue
                      d = yaml.safe_load(open(os.path.join(tech_dir, f)))
                      w.writerow([d['id'], d['name'], d.get('description',''),
                                  ';'.join(d.get('tactics',[])), ';'.join(d.get('platforms',[])),
                                  d.get('framework',''), d.get('deprecated', False)])

          with open('subtechniques.csv', 'w', newline='') as fp:
              w = csv.writer(fp)
              w.writerow(['id', 'name', 'description', 'parent_technique', 'tactics', 'platforms', 'framework', 'deprecated'])
              for fw_name in @@FW_SLUGS@@:
                  subtech_dir = os.path.join(fw_name, 'objects', 'subtechniques')
                  if not os.path.isdir(subtech_dir): continue
                  for f in sorted(os.listdir(subtech_dir)):
                      if not f.endswith('.yaml'): continue
                      d = yaml.safe_load(open(os.path.join(subtech_dir, f)))
                      w.writerow([d['id'], d['name'], d.get('description',''),
                                  d.get('parent_technique',''),
                                  ';'.join(d.get('tactics',[])), ';'.join(d.get('platforms',[])),
                                  d.get('framework',''), d.get('deprecated', False)])

          with zipfile.ZipFile(f'@@ARTIFACT@@-v{version}.csv.zip', 'w') as zf:
              zf.write('tactics.csv')
              zf.write('techniques.csv')
              zf.write('subtechniques.csv')
          PYEOF

      - name: Build translated exports
        run: |
          python - <<'PYEOF'
          import os, yaml, json, csv, zipfile, uuid, re
          from datetime import datetime, timezone

          repo_full = "${{ github.repository }}"

          def sanitize_phase_name(s):
              return re.sub(r'[^a-z0-9-]', '', s)

          def normalize_ts(ts):
              if not ts: return None
              return re.sub(r'\+00:?00$', 'Z', ts)

          def doc_url(framework, obj_type, framework_id):
              return f"https://github.com/{repo_full}/blob/main/{framework}/documentation/{obj_type}/{framework_id}.md"

          version = "${{ steps.version.outputs.VERSION }}"
          has_translations = any(
              os.path.isdir(os.path.join(fw, 'translations'))
              for fw in @@FW_SLUGS@@
          )
          if not has_translations:
              print('No translations directory — skipping')
              exit(0)

          marking = "marking-definition--f79f25d2-8b96-4580-b169-eb7b613a7c31"
          now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.000Z")

          FRAMEWORK_KILL_CHAIN = @@KC_MAP@@

          def get_kill_chain(data):
              fw = data.get("framework", "")
              return FRAMEWORK_KILL_CHAIN.get(fw, fw)

          # Load English data as base from both frameworks
          en_tactics = {}
          en_techniques = {}
          en_subtechniques = {}
          for fw_name in @@FW_SLUGS@@:
              tac_dir = os.path.join(fw_name, 'objects', 'tactics')
              if os.path.isdir(tac_dir):
                  for f in sorted(os.listdir(tac_dir)):
                      if not f.endswith('.yaml'): continue
                      d = yaml.safe_load(open(os.path.join(tac_dir, f)))
                      en_tactics[d['id']] = d
              tech_dir = os.path.join(fw_name, 'objects', 'techniques')
              if os.path.isdir(tech_dir):
                  for f in sorted(os.listdir(tech_dir)):
                      if not f.endswith('.yaml'): continue
                      d = yaml.safe_load(open(os.path.join(tech_dir, f)))
                      en_techniques[d['id']] = d
              subtech_dir = os.path.join(fw_name, 'objects', 'subtechniques')
              if os.path.isdir(subtech_dir):
                  for f in sorted(os.listdir(subtech_dir)):
                      if not f.endswith('.yaml'): continue
                      d = yaml.safe_load(open(os.path.join(subtech_dir, f)))
                      en_subtechniques[d['id']] = d

          # Collect all translations from both framework dirs
          all_langs = set()
          for fw in @@FW_SLUGS@@:
              tr_base = os.path.join(fw, 'translations')
              if os.path.isdir(tr_base):
                  for lang in os.listdir(tr_base):
                      if os.path.isdir(os.path.join(tr_base, lang)):
                          all_langs.add(lang)

          for lang in sorted(all_langs):
              # Load translations for this language from both frameworks
              tr = {}
              for fw in @@FW_SLUGS@@:
                  for subdir in ['tactics', 'techniques', 'subtechniques']:
                      path = os.path.join(fw, 'translations', lang, subdir)
                      if not os.path.isdir(path): continue
                      for f in os.listdir(path):
                          if not f.endswith('.yaml'): continue
                          d = yaml.safe_load(open(os.path.join(path, f)))
                          tr[d['id']] = d

              if not tr: continue

              # ── STIX 2.1 bundle ──
              bundle = {"type": "bundle", "id": f"bundle--{uuid.uuid4()}", "spec_version": "2.1", "objects": []}
              technique_ids = {}
              related_pairs = []

              bundle["objects"].append({
                  "type": "identity", "spec_version": "2.1", "id": identity_id,
                  "created": "2024-01-01T00:00:00.000Z", "modified": "2024-01-01T00:00:00.000Z",
                  "name": "@@ORG_NAME@@",
                  "description": "@@ORG_DESC@@",
                  "identity_class": "organization"
              })
              bundle["objects"].append({
                  "type": "marking-definition", "spec_version": "2.1", "id": marking,
                  "created": "2024-01-01T00:00:00.000Z", "definition_type": "statement",
                  "name": "CC-BY-SA-4.0",
                  "definition": {"statement": "CC-BY-SA-4.0 @@ORG_NAME@@"},
                  "created_by_ref": identity_id
              })

              # STIX 2.1 extension-definition for the framework-membership custom property
              bundle["objects"].append({
                  "type": "extension-definition", "spec_version": "2.1",
                  "id": "@@EXT_ID@@",
                  "created": "2026-03-30T00:00:00.000Z", "modified": "2026-03-30T00:00:00.000Z",
                  "name": "@@EXT_NAME@@",
                  "description": "@@EXT_DESC@@",
                  "schema": "@@EXT_SCHEMA@@",
                  "version": "1.0",
                  "extension_types": ["property-extension"],
                  "created_by_ref": "@@IDENTITY_ID@@"
              })

              for tid, tac in sorted(en_tactics.items()):
                  t = tr.get(tid, {})
                  kill_chain = get_kill_chain(tac)
                  obj = {
                      "type": "x-mitre-tactic", "spec_version": "2.1",
                      "id": f"x-mitre-tactic--{uuid.uuid4()}",
                      "created": normalize_ts(tac.get("created")) or now, "modified": normalize_ts(tac.get("modified")) or now,
                      "name": t.get("name", tac["name"]),
                      "description": t.get("description", tac.get("description", "")),
                      "x_mitre_shortname": sanitize_phase_name(tac["shortname"]),
                      "@@PROP_FW@@": tac.get("framework", ""),
                      "external_references": [{"source_name": "@@SRC@@", "external_id": tid, "url": doc_url(tac.get("framework", ""), "tactics", tid)}],
                      "object_marking_refs": [marking]
                  }
                  if tac.get("deprecated"):
                      obj["x_mitre_deprecated"] = True
                      obj["revoked"] = True
                  bundle["objects"].append(obj)

              tech_sorted = sorted(en_techniques.items(), key=lambda x: (1 if '.' in x[0] else 0, x[0]))
              for tid, tech in tech_sorted:
                  t = tr.get(tid, {})
                  stix_id = f"attack-pattern--{uuid.uuid4()}"
                  technique_ids[tid] = stix_id
                  kill_chain = get_kill_chain(tech)
                  ext_refs = [{"source_name": "@@SRC@@", "external_id": tid, "url": doc_url(tech.get("framework", ""), "techniques", tid)}]
                  for report in tech.get("reports", []):
                      ref = {"source_name": "report", "url": report["url"]}
                      if report.get("excerpt"): ref["description"] = report["excerpt"]
                      ext_refs.append(ref)
                  obj = {
                      "type": "attack-pattern", "spec_version": "2.1", "id": stix_id,
                      "created": normalize_ts(tech.get("created")) or now, "modified": normalize_ts(tech.get("modified")) or now,
                      "name": t.get("name", tech["name"]),
                      "description": t.get("description", tech.get("description", "")),
                      "@@PROP_FW@@": tech.get("framework", ""),
                      "kill_chain_phases": [{"kill_chain_name": kill_chain, "phase_name": sanitize_phase_name(tc)} for tc in tech.get("tactics", [])],
                      "x_mitre_platforms": tech.get("platforms", []),
                      "external_references": ext_refs,
                      "object_marking_refs": [marking]
                  }
                  if tech.get("deprecated"):
                      obj["x_mitre_deprecated"] = True
                      obj["revoked"] = True
                  bundle["objects"].append(obj)
                  for rt in tech.get("related_techniques", []):
                      rt_id = rt["id"]
                      rt_desc = rt.get("description", "")
                      pair = tuple(sorted([tid, rt_id]))
                      if pair not in related_pairs: related_pairs.append((pair, rt_desc))

              # Sub-techniques
              subtechnique_of_rels = []
              for stid, subtech in sorted(en_subtechniques.items()):
                  t = tr.get(stid, {})
                  stix_id = f"attack-pattern--{uuid.uuid4()}"
                  technique_ids[stid] = stix_id
                  kill_chain = get_kill_chain(subtech)
                  ext_refs = [{"source_name": "@@SRC@@", "external_id": stid, "url": doc_url(subtech.get("framework", ""), "subtechniques", stid)}]
                  for report in subtech.get("reports", []):
                      ref = {"source_name": "report", "url": report["url"]}
                      if report.get("excerpt"): ref["description"] = report["excerpt"]
                      ext_refs.append(ref)
                  obj = {
                      "type": "attack-pattern", "spec_version": "2.1", "id": stix_id,
                      "created": normalize_ts(subtech.get("created")) or now, "modified": normalize_ts(subtech.get("modified")) or now,
                      "name": t.get("name", subtech["name"]),
                      "description": t.get("description", subtech.get("description", "")),
                      "@@PROP_FW@@": subtech.get("framework", ""),
                      "x_mitre_is_subtechnique": True,
                      "kill_chain_phases": [{"kill_chain_name": kill_chain, "phase_name": sanitize_phase_name(tc)} for tc in subtech.get("tactics", [])],
                      "x_mitre_platforms": subtech.get("platforms", []),
                      "external_references": ext_refs,
                      "object_marking_refs": [marking]
                  }
                  if subtech.get("deprecated"):
                      obj["x_mitre_deprecated"] = True
                      obj["revoked"] = True
                  bundle["objects"].append(obj)
                  parent_id = subtech.get("parent_technique", "")
                  if parent_id:
                      subtechnique_of_rels.append((stid, parent_id))
                  for rt in subtech.get("related_techniques", []):
                      rt_id = rt["id"]
                      rt_desc = rt.get("description", "")
                      pair = tuple(sorted([stid, rt_id]))
                      if pair not in related_pairs: related_pairs.append((pair, rt_desc))

              for (src_id, tgt_id), desc in related_pairs:
                  src_stix, tgt_stix = technique_ids.get(src_id), technique_ids.get(tgt_id)
                  if src_stix and tgt_stix:
                      rel = {
                          "type": "relationship", "spec_version": "2.1",
                          "id": f"relationship--{uuid.uuid4()}", "created": now, "modified": now,
                          "relationship_type": "related-to",
                          "source_ref": src_stix, "target_ref": tgt_stix,
                          "object_marking_refs": [marking]
                      }
                      if desc: rel["description"] = desc
                      bundle["objects"].append(rel)

              # Create subtechnique-of relationships
              for sub_id, parent_id in subtechnique_of_rels:
                  sub_stix, parent_stix = technique_ids.get(sub_id), technique_ids.get(parent_id)
                  if sub_stix and parent_stix:
                      bundle["objects"].append({
                          "type": "relationship", "spec_version": "2.1",
                          "id": f"relationship--{uuid.uuid4()}", "created": now, "modified": now,
                          "relationship_type": "subtechnique-of",
                          "source_ref": sub_stix, "target_ref": parent_stix,
                          "object_marking_refs": [marking]
                      })

              # Add grouping objects for each framework
              ext_ref = {"@@EXT_ID@@": {"extension_type": "property-extension"}}
              for fw_name, kill_chain in FRAMEWORK_KILL_CHAIN.items():
                  bundle["objects"].append({
                      "type": "grouping", "spec_version": "2.1",
                      "id": f"grouping--{uuid.uuid4()}", "created": now, "modified": now,
                      "name": f"@@FW_NAME@@ {fw_name.title()} Framework",
                      "@@PROP_VER@@": version,
                      "description": f"All objects belonging to the @@FW_NAME@@ {fw_name} framework (kill_chain_name: {kill_chain}).",
                      "context": "suspicious-activity",
                      "extensions": ext_ref,
                      "object_refs": [o["id"] for o in bundle["objects"] if o.get("@@PROP_FW@@") == fw_name],
                      "object_marking_refs": [marking]
                  })

              with open(f"@@ARTIFACT@@-v{version}.{lang}.stix.json", "w") as fp:
                  json.dump(bundle, fp, indent=2, ensure_ascii=False)

              # ── Flat JSON ──
              flat = {"language": lang, "tactics": [], "techniques": [], "subtechniques": []}
              for tid, tac in sorted(en_tactics.items()):
                  t = tr.get(tid, {})
                  flat["tactics"].append({
                      "id": tid,
                      "name": t.get("name", tac["name"]),
                      "description": t.get("description", tac.get("description", "")),
                      "shortname": tac["shortname"],
                      "framework": tac.get("framework", ""),
                  })
              for tid, tech in sorted(en_techniques.items()):
                  t = tr.get(tid, {})
                  flat["techniques"].append({
                      "id": tid,
                      "name": t.get("name", tech["name"]),
                      "description": t.get("description", tech.get("description", "")),
                      "tactics": tech.get("tactics", []),
                      "platforms": tech.get("platforms", []),
                      "framework": tech.get("framework", ""),
                  })
              for stid, subtech in sorted(en_subtechniques.items()):
                  t = tr.get(stid, {})
                  flat["subtechniques"].append({
                      "id": stid,
                      "name": t.get("name", subtech["name"]),
                      "description": t.get("description", subtech.get("description", "")),
                      "parent_technique": subtech.get("parent_technique", ""),
                      "tactics": subtech.get("tactics", []),
                      "platforms": subtech.get("platforms", []),
                      "framework": subtech.get("framework", ""),
                  })
              with open(f"@@ARTIFACT@@-v{version}.{lang}.json", "w") as fp:
                  json.dump(flat, fp, indent=2, ensure_ascii=False)

              # ── CSV ──
              with open(f'tactics_{lang}.csv', 'w', newline='') as fp:
                  w = csv.writer(fp)
                  w.writerow(['id', 'name', 'description', 'shortname', 'framework', 'deprecated'])
                  for tid, tac in sorted(en_tactics.items()):
                      t = tr.get(tid, {})
                      w.writerow([tid, t.get('name', tac['name']), t.get('description', tac.get('description','')),
                                  tac['shortname'], tac.get('framework',''), tac.get('deprecated', False)])
              with open(f'techniques_{lang}.csv', 'w', newline='') as fp:
                  w = csv.writer(fp)
                  w.writerow(['id', 'name', 'description', 'tactics', 'platforms', 'framework', 'deprecated'])
                  for tid, tech in sorted(en_techniques.items()):
                      t = tr.get(tid, {})
                      w.writerow([tid, t.get('name', tech['name']),
                                  t.get('description', tech.get('description','')),
                                  ';'.join(tech.get('tactics',[])), ';'.join(tech.get('platforms',[])),
                                  tech.get('framework',''), tech.get('deprecated', False)])
              with open(f'subtechniques_{lang}.csv', 'w', newline='') as fp:
                  w = csv.writer(fp)
                  w.writerow(['id', 'name', 'description', 'parent_technique', 'tactics', 'platforms', 'framework', 'deprecated'])
                  for stid, subtech in sorted(en_subtechniques.items()):
                      t = tr.get(stid, {})
                      w.writerow([stid, t.get('name', subtech['name']),
                                  t.get('description', subtech.get('description','')),
                                  subtech.get('parent_technique',''),
                                  ';'.join(subtech.get('tactics',[])), ';'.join(subtech.get('platforms',[])),
                                  subtech.get('framework',''), subtech.get('deprecated', False)])
              with zipfile.ZipFile(f'@@ARTIFACT@@-v{version}.{lang}.csv.zip', 'w') as zf:
                  zf.write(f'tactics_{lang}.csv')
                  zf.write(f'techniques_{lang}.csv')
                  zf.write(f'subtechniques_{lang}.csv')

              print(f'{lang}: STIX + JSON + CSV exports built ({len(tr)} translated items)')
          PYEOF

      - name: Upload release artefacts
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          TAG="${{ steps.version.outputs.TAG }}"
          VERSION="${{ steps.version.outputs.VERSION }}"
          gh release upload "$TAG" --clobber \
            "@@ARTIFACT@@-v${VERSION}.stix.json" \
            "@@ARTIFACT@@-v${VERSION}.json" \
            "@@ARTIFACT@@-v${VERSION}.csv.zip"
          # Upload translated artefacts if they exist
          for f in @@ARTIFACT@@-v${VERSION}.*.stix.json @@ARTIFACT@@-v${VERSION}.*.json @@ARTIFACT@@-v${VERSION}.*.csv.zip; do
            [ -f "$f" ] || continue
            gh release upload "$TAG" --clobber "$f"
          done
YAML;
    return applyProfileToWorkflow($yaml);
}
