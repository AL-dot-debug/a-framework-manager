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
 * Framework Manager — DeepL Translation
 */

require_once __DIR__ . '/data.php';

/**
 * Translate a single text string via the DeepL API.
 *
 * @param string $text       Source text (English)
 * @param string $targetLang Target language code (e.g. "FR", "DE")
 * @param string $apiKey     DeepL API key
 * @return string Translated text
 * @throws RuntimeException on API error
 */
function deeplTranslate(string $text, string $targetLang, string $apiKey): string {
    if (trim($text) === '') return '';

    // DeepL Free API uses api-free.deepl.com, Pro uses api.deepl.com
    $host = str_ends_with($apiKey, ':fx') ? 'api-free.deepl.com' : 'api.deepl.com';

    $ch = curl_init("https://$host/v2/translate");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'text'        => [$text],
            'source_lang' => 'EN',
            'target_lang' => strtoupper($targetLang),
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException("DeepL request failed: $error");
    }
    if ($httpCode !== 200) {
        $body = json_decode($response, true);
        $msg = $body['message'] ?? "HTTP $httpCode";
        throw new RuntimeException("DeepL API error: $msg");
    }

    $result = json_decode($response, true);
    return $result['translations'][0]['text'] ?? '';
}

/**
 * Translate a batch of texts via the DeepL API (single request, up to 50 texts).
 *
 * @param array  $texts      Array of source texts
 * @param string $targetLang Target language code
 * @param string $apiKey     DeepL API key
 * @return array Translated texts in same order
 */
function deeplTranslateBatch(array $texts, string $targetLang, string $apiKey): array {
    if (empty($texts)) return [];

    $host = str_ends_with($apiKey, ':fx') ? 'api-free.deepl.com' : 'api.deepl.com';

    $ch = curl_init("https://$host/v2/translate");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'text'        => array_values($texts),
            'source_lang' => 'EN',
            'target_lang' => strtoupper($targetLang),
        ]),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new RuntimeException("DeepL request failed: $error");
    }
    if ($httpCode !== 200) {
        $body = json_decode($response, true);
        $msg = $body['message'] ?? "HTTP $httpCode";
        throw new RuntimeException("DeepL API error: $msg");
    }

    $result = json_decode($response, true);
    $translated = [];
    foreach ($result['translations'] ?? [] as $i => $t) {
        $translated[] = $t['text'] ?? '';
    }
    return $translated;
}

/**
 * Translate all non-deprecated framework items for the given languages.
 * Uses a local cache to avoid re-translating unchanged content.
 *
 * @param array  $fw          STIX framework bundle
 * @param array  $targetLangs Array of language codes (e.g. ['fr', 'de'])
 * @param string $apiKey      DeepL API key
 * @return array [lang => [framework_id => ['name' => ..., 'description' => ...]]]
 */
function translateFramework(array $fw, array $targetLangs, string $apiKey): array {
    $cache = getTranslationCache();
    $results = [];

    // Collect all translatable items (non-deprecated tactics + techniques)
    $items = [];
    foreach ($fw['objects'] ?? [] as $obj) {
        $type = $obj['type'] ?? '';
        if ($type !== 'x-mitre-tactic' && $type !== 'attack-pattern') continue;
        if (!empty($obj['x_mitre_deprecated']) || !empty($obj['revoked'])) continue;

        $frameworkId = '';
        foreach ($obj['external_references'] ?? [] as $ref) {
            if (!empty($ref['external_id'])) { $frameworkId = $ref['external_id']; break; }
        }
        if (!$frameworkId) continue;

        $items[$frameworkId] = [
            'name'        => $obj['name'] ?? '',
            'description' => $obj['description'] ?? '',
        ];
    }

    foreach ($targetLangs as $lang) {
        $results[$lang] = [];

        // Separate cached vs uncached items
        $toTranslate = []; // framework_id => [name, description]
        foreach ($items as $frameworkId => $item) {
            $hash = md5($item['name'] . "\0" . $item['description']);
            $cacheKey = "$lang:$frameworkId";
            if (isset($cache[$cacheKey]) && ($cache[$cacheKey]['hash'] ?? '') === $hash) {
                // Cache hit
                $results[$lang][$frameworkId] = [
                    'name'        => $cache[$cacheKey]['name'],
                    'description' => $cache[$cacheKey]['description'],
                ];
            } else {
                $toTranslate[$frameworkId] = $item;
            }
        }

        if (empty($toTranslate)) continue;

        // Batch translate: interleave names and descriptions for efficiency
        // DeepL supports up to 50 texts per request, so chunk accordingly
        $ids = array_keys($toTranslate);
        $chunks = array_chunk($ids, 25); // 25 items = 50 texts (name + description)

        foreach ($chunks as $chunkIds) {
            $texts = [];
            foreach ($chunkIds as $id) {
                $texts[] = $toTranslate[$id]['name'];
                $texts[] = $toTranslate[$id]['description'];
            }

            $translated = deeplTranslateBatch($texts, $lang, $apiKey);

            foreach ($chunkIds as $i => $frameworkId) {
                $tName = $translated[$i * 2] ?? '';
                $tDesc = $translated[$i * 2 + 1] ?? '';

                $results[$lang][$frameworkId] = [
                    'name'        => $tName,
                    'description' => $tDesc,
                ];

                // Update cache
                $hash = md5($toTranslate[$frameworkId]['name'] . "\0" . $toTranslate[$frameworkId]['description']);
                $cache["$lang:$frameworkId"] = [
                    'hash'        => $hash,
                    'name'        => $tName,
                    'description' => $tDesc,
                ];
            }
        }
    }

    saveTranslationCache($cache);
    return $results;
}
