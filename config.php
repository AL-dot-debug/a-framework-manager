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
 * Framework Manager — Configuration
 *
 * This file returns default configuration values.
 * Runtime overrides are stored in data/config.json via the admin UI.
 *
 * The `profile` section makes the tool framework-agnostic: everything that is
 * specific to a particular knowledge base (branding, terminology, ID prefixes,
 * STIX namespace, kill-chain names, URLs) lives here. The defaults below are
 * neutral placeholders. A concrete framework is applied by overriding `profile`
 * in data/config.json — see profiles/*.json for ready-made, importable profiles.
 */

return [
    'github' => [
        'app_id'           => '',
        'private_key_path' => DATA_DIR . '/.github-app.pem',
        'installation_id'  => '',
        'owner'            => '',
        'repo'             => '',
        'base_branch'      => 'main',
        'dev_branch'       => 'dev',
    ],
    'translations' => [
        'deepl_api_key' => '',
        'languages'     => '',  // comma-separated: "fr,de,es"
    ],
    'public_api' => [
        'token' => '',
    ],

    // AGPL section 13: if you run a modified version, set this to the URL of
    // YOUR source. It is shown alongside the (locked) upstream link. Editable
    // in Settings; kept separate from the framework profile so profile imports
    // never touch it.
    'instance_source_url' => '',

    // ── Framework profile (neutral defaults) ─────────────────────────────────
    // Override the whole section in data/config.json to rebrand for any
    // framework. See profiles/ for ready-made, importable example profiles.
    'profile' => [
        'product_name'   => 'Framework Manager',   // application/brand name
        'framework_name' => 'Framework',           // the managed knowledge base
        'org_name'       => 'The Foundation',      // governing organisation
        'org_short'      => 'Foundation',          // short org name
        'org_description'=> 'A framework for describing and understanding a domain.', // identity description
        'source_name'    => 'FRAMEWORK',           // external_references source_name
        'artifact_slug'  => 'framework',           // release artefact basename: <slug>-v<version>.*

        // Base URL used for schema, extension-definition and doc links.
        'base_url' => 'https://example.com',

        // STIX custom-property namespace. Yields "<prefix>_framework" (object
        // membership) and "<prefix>_version" (grouping version).
        'stix_property_prefix' => 'x_framework',

        // STIX extension-definition describing the custom properties above.
        'extension_definition_id'   => 'extension-definition--00000000-0000-4000-8000-000000000000',
        'extension_definition_name' => 'Framework Membership',
        'identity_ref'              => 'identity--00000000-0000-4000-8000-000000000001',

        // Sub-frameworks, keyed by an internal slug. Slugs are the stored
        // membership value and the ?framework= parameter; admins can add/rename
        // them via Settings. The three levels below are fixed STIX roles
        // (tactic → technique → sub-technique); only their display names vary.
        'subframeworks' => [
            'primary' => [
                'label'           => 'Primary',
                'id_prefix'       => 'PRI',
                'kill_chain_name' => 'framework-primary',
                'levels' => [
                    'tactic'       => ['label' => 'Tactic',        'plural' => 'Tactics'],
                    'technique'    => ['label' => 'Technique',     'plural' => 'Techniques'],
                    'subtechnique' => ['label' => 'Sub-technique', 'plural' => 'Sub-techniques'],
                ],
            ],
            'secondary' => [
                'label'           => 'Secondary',
                'id_prefix'       => 'SEC',
                'kill_chain_name' => 'framework-secondary',
                'levels' => [
                    'tactic'       => ['label' => 'Tactic',        'plural' => 'Tactics'],
                    'technique'    => ['label' => 'Technique',     'plural' => 'Techniques'],
                    'subtechnique' => ['label' => 'Sub-technique', 'plural' => 'Sub-techniques'],
                ],
            ],
        ],
    ],
];
