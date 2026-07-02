# Framework Manager

Framework Manager is a collaborative, framework-agnostic web application for building and maintaining a STIX 2.1 knowledge base of tactics, techniques and sub-techniques. Teams use it to propose, review and release changes to a framework through a structured, auditable workflow.

## Contents

- [Key features](#key-features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How it is organised](#how-it-is-organised)
- [Data and storage](#data-and-storage)
- [Security](#security)
- [Publishing](#publishing)
- [Documentation](#documentation)
- [Licence](#licence)

## Key features

- **Framework profiles.** Every framework-specific value (names, terminology, ID prefixes, STIX property namespace, kill-chain names, base URLs, organisation identity) lives in one profile. Edit it through a form in Settings, or import a bundled profile. A neutral starter profile ships in `profiles/default.json`.
- **Arbitrary sub-frameworks.** A framework can have one or more sub-frameworks (for example the way MITRE ATT&CK groups content into Enterprise, Mobile and ICS). Each has its own ID prefix and terminology. Add or remove them at any time.
- **Three-level hierarchy.** Every sub-framework follows a fixed STIX structure of tactic, technique and sub-technique. Only the display names are configurable; the underlying roles stay standard so existing STIX tooling keeps working.
- **Proposal and review workflow.** Contributors submit change proposals (add, edit, deprecate). Nobody can approve their own change. Every proposal stores before and after snapshots and reserves its ID at submission time.
- **Releases and versioning.** Admins snapshot the framework as versioned releases following semantic versioning (major, minor, patch).
- **GitHub publishing.** Approved changes are converted to YAML and published to a GitHub repository via a pull request, using a GitHub App and the Git Data API (no git binary required on the server). Continuous integration then rebuilds STIX, flat JSON and CSV artefacts.
- **Automatic translations.** Framework content can be translated at publish time through the DeepL API, with a content-hash cache so unchanged text is not re-translated.
- **Governance.** A Technical Steering Committee page shows members, a charter and a code of conduct. Admins edit the charter and code of conduct with a Markdown editor in Settings; users see the rendered result.
- **Public API.** Bearer-token endpoints let an external website submit change proposals and list committee members.
- **Audit log.** Every significant action is recorded, with filtering by user, level and action.
- **First-run setup wizard.** A guided installer creates the first admin account, defines the framework and first sub-framework, and runs environment and security checks before the application is used.

## Requirements

- **PHP 8.1 or newer**, with the `json`, `openssl` and `curl` extensions (`mbstring` is recommended).
- **Apache 2.4** with `mod_rewrite`, serving PHP through PHP-FPM.
- A writable `data/` directory owned by the web server user (typically `www-data`).

The application runs directly from source.

## Installation

1. Copy the project into a directory served by Apache.

2. Open the site in a browser. On a fresh instance you are taken straight to the setup wizard (`setup.php`).

4. Complete the wizard:
   - It runs environment and security checks (PHP version, required extensions, writable and protected data directory).
   - You create the first administrator account.
   - You give your framework a name and define its first sub-framework.
   - The technical values (STIX namespace, artefact slug, identifiers) are derived for you and can be refined later in Settings.

5. When setup finishes you are signed in automatically. Once installed, the wizard locks itself and the application reminds administrators to delete `setup.php`:


## Configuration

Everything is configured in the browser, under **Settings** (administrators only). Settings is organised into tabs:

- **Framework Profile.** Define the framework name, organisation, terminology, ID prefixes, kill-chain names and STIX namespace. Add or remove sub-frameworks, rename the level terms, and generate STIX identifiers automatically. You can also import a bundled profile from `profiles/`.
- **GitHub.** Configure the GitHub App used for publishing (see `docs/GITHUB_SETUP.md`).
- **Translations.** Provide a DeepL API key and the target languages.
- **Public API.** Generate the bearer token used by the public endpoints.
- **Governance.** Edit the your framework charter and code of conduct in Markdown, with a live preview.

Each integration tab shows an at-a-glance status indicator, and can be saved or cleared independently.

## How it is organised

```
index.php     Login page
app.php       Single-page application shell (Alpine.js)
api.php       REST endpoints, dispatched by an ?action= parameter
setup.php     First-run setup wizard (delete after installation)
reset.php     Admin-only reset of instance data (keeps user accounts)
config.php    Default configuration, including the neutral default profile
style.css     All styles
seed.json     Empty STIX seed used on a fresh install

includes/
  auth.php    Sessions, login, brute-force protection
  data.php    JSON storage, STIX helpers, profile accessors
  yaml.php    STIX to YAML conversion for publishing
  github.php  GitHub App authentication and publishing
  deepl.php   DeepL translation client
  log.php     Activity audit log

profiles/     Importable framework profiles (default.json is a neutral starter)
docs/         Setup and API guides
data/         All runtime data (JSON files, protected from web access)
```

The frontend is a single Alpine.js component. The active profile is injected into the page and drives all terminology and branding. Every framework-specific value is read from the profile at runtime.

## Data and storage

All state is stored as JSON files under `data/`: the framework bundle, change proposals, releases, users, configuration, submissions, translation cache and the audit log. There is no external database. File access is serialised with advisory locks for concurrency safety.

## Security

- **The data directory is never web-served.** On first run the application writes a deny rule (`data/.htaccess`) so files such as the user database, API keys and the GitHub App private key cannot be downloaded. Confirm your web server honours `.htaccess`, or block the directory in your server configuration.
- **Setup is gated.** Until the wizard has run, the application refuses all requests and redirects to `setup.php`. This closes any default-account access before an administrator has been created.
- **Brute-force protection.** Repeated failed logins from the same address are rate-limited, and login timing is normalised so that unknown usernames cannot be distinguished from wrong passwords.
- **Session hardening.** Session cookies are HTTP-only with a strict same-site policy, and the session identifier is regenerated on login.
- **Least privilege.** Only administrators can manage users, settings, releases and publishing.

## Publishing

Publishing does not require a git binary on the server. Framework Manager authenticates as a GitHub App, converts approved changes to a simplified YAML format, and commits them to a development branch through the Git Data API before opening a pull request. Continuous integration in the target repository validates the YAML against JSON schemas and rebuilds the STIX bundle, a flat JSON export and CSV archives, including per-language variants where translations exist.

See `docs/GITHUB_SETUP.md` for how to create and configure the GitHub App.

## Documentation

- `docs/GITHUB_SETUP.md` explains how to set up the GitHub App used for publishing.
- `docs/PUBLIC_API.md` documents the public, bearer-token endpoints.
- `docs/README_framework.md` describes the structure of a published framework repository.

## Licence

The application code is licensed under the **GNU Affero General Public License, version 3 (AGPL-3.0)**. See the [`LICENSE`](LICENSE) file for the full text.

The AGPL is chosen deliberately because Framework Manager is normally used over a network. Under section 13, if you run a modified version and let other people interact with it over a network, you must also offer those users the corresponding source code of your modified version.

To make this straightforward, the running application always shows a source link:

- An **upstream link** to [intheopen.eu](https://intheopen.eu) **is shown at all times and cannot be removed**.
- If you modify the software, set **your** source URL under **Settings, Framework Profile, Source code**. It is then shown as "This instance's source", which is what section 13 requires.
