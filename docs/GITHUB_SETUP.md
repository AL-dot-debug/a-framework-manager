# GitHub Setup Guide

How to set up the GitHub side of things so the Framework Manager can publish to your framework repository.

## 1. Create the Repository

- Go to github.com → **New repository**
- Name: `my-framework`
- Visibility: your choice (public or private)
- Initialize with a README
- Add a license: **CC-BY-SA-4.0** (create `LICENSE.md` manually if not in the dropdown)
- After creation, the `main` branch exists — no need to create `dev`, the tool creates it on first publish

## 2. Protect the `main` Branch

- Go to **Settings → Rules → Rulesets** (or **Branches** on older plans)
- Click **New ruleset** → Branch ruleset
- Target: `main`
- Enable:
  - **Require status checks to pass** — add `validate` once the first PR triggers it
  - **Block force pushes**
- This ensures nothing lands on `main` without a PR + passing validation

## 3. Create a GitHub App

This is how the tool authenticates — no personal tokens needed.

1. Go to **Settings → Developer settings → GitHub Apps → New GitHub App**
2. Fill in:
   - **App name**: `Framework Manager` (must be globally unique)
   - **Homepage URL**: your tool's URL or `https://example.com`
   - **Webhook**: uncheck **Active** (you don't need webhooks)
3. **Permissions** (Repository permissions only):
   - **Contents**: Read & Write (push commits, read files)
   - **Pull requests**: Read & Write (create PRs)
   - **Metadata**: Read-only (required, auto-selected)
4. **Where can this app be installed?**: "Only on this account"
5. Click **Create GitHub App**
6. Note the **App ID** shown at the top of the page

## 4. Generate a Private Key

- On the App's page, scroll to **Private keys**
- Click **Generate a private key**
- A `.pem` file downloads — this is what you paste into the tool's Settings page

## 5. Install the App on Your Repository

- On the App's page, click **Install App** in the left sidebar
- Select your account
- Choose **Only select repositories** → select `my-framework`
- Click **Install**
- After installation, note the **Installation ID** from the URL:
  `github.com/settings/installations/INSTALLATION_ID` — it's the number at the end

## 6. Configure the Tool

In the Framework Manager, go to **Settings** and enter:

| Field | Value |
|---|---|
| GitHub App ID | The App ID from step 3 |
| Installation ID | The number from step 5 |
| Repository Owner | Your GitHub username or org (e.g. `my-org`) |
| Repository Name | `my-framework` |
| Private Key | Paste the entire `.pem` file contents |

Click **Save Configuration**, then **Test Connection**. You should see "Connected to owner/my-framework".

## 7. First Publish

- Make sure you have at least one approved change in the tool (or even zero — the first publish will push the full framework)
- Go to **Releases** → **Publish to GitHub**
- Enter a version like `1.0.0`
- Click **Publish**
- The tool will:
  - Create the `dev` branch from `main`
  - Push all YAML files, schemas, CI workflows, and CHANGELOG.md
  - Open a PR from `dev` → `main`
- The `validate.yml` workflow will run on the PR automatically
- Review and merge the PR on GitHub
- Tag the merge commit as `v1.0.0` to trigger the release workflow

## 8. Tagging a Release

After merging the PR, tag it to trigger the release artefact build:

```bash
git pull origin main
git tag v1.0.0
git push origin v1.0.0
```

Or on GitHub: **Releases → Draft a new release → Choose tag → Create new tag `v2.0.0` on publish**.

The `release.yml` workflow will build and attach these artefacts to the GitHub Release:

- `framework-v1.0.0.stix.json` — full STIX 2.1 bundle
- `framework-v1.0.0.json` — flat JSON export
- `framework-v1.0.0.csv.zip` — CSV export (one file per object type)

## Quick Reference

| What | Where |
|---|---|
| App ID | GitHub → Settings → Developer settings → GitHub Apps → your app |
| Installation ID | GitHub → Settings → Installations → click your app → ID in URL |
| Private key | Downloaded `.pem` file from the App settings |
| Branch protection | Repo → Settings → Rules → Rulesets |
| CI workflow runs | Repo → Actions tab |
| Release artefacts | Repo → Releases (appear after tagging) |
