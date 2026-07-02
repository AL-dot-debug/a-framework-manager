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
 * Framework Manager — Main SPA
 */
require_once __DIR__ . '/includes/auth.php';

initDataDir();
if (!isInstalled()) { header('Location: setup.php'); exit; }

requireAuth();
$currentUser = getCurrentUser();

$profile     = getProfile();
$productName = $profile['product_name']   ?? 'Framework Manager';
$fwName      = $profile['framework_name'] ?? 'Framework';

// Security: warn admins if the one-time setup script is still on disk.
$setupStillPresent = ($currentUser['role'] ?? '') === 'admin' && is_file(__DIR__ . '/setup.php');

// AGPL: locked upstream link + optional operator-set link to their modified source.
$instanceSourceUrl = getConfig()['instance_source_url'] ?? '';

// Subset of the profile exposed to the frontend for dynamic terminology.
$jsProfile = [
    'product_name'   => $productName,
    'framework_name' => $fwName,
    'subframeworks'  => [],
];
foreach ($profile['subframeworks'] ?? [] as $slug => $conf) {
    $jsProfile['subframeworks'][$slug] = [
        'label'     => $conf['label'] ?? ucfirst($slug),
        'id_prefix' => $conf['id_prefix'] ?? '',
        'levels'    => $conf['levels'] ?? [],
    ];
}

// Server-render helpers for static terminology (plural level labels + sub-framework labels).
$sfLabel = fn(string $fw): string => htmlspecialchars($profile['subframeworks'][$fw]['label'] ?? ucfirst($fw));
$lvlPlural = fn(string $fw, string $lvl): string => htmlspecialchars(
    $profile['subframeworks'][$fw]['levels'][$lvl]['plural']
        ?? (($profile['subframeworks'][$fw]['levels'][$lvl]['label'] ?? ucfirst($lvl)) . 's')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($productName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,300;1,6..72,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css">
<style>
  /* Charter content scoped overrides for marked.js heading IDs */
  .charter-content h2[id] { scroll-margin-top: calc(var(--nav-h) + 16px); }
</style>

</head>
<body x-data="frameworkApp" x-init="init()">

<!-- ── NAV ── -->
<nav class="app-nav">
<?php
    $brandMark = strtoupper(substr($productName, 0, 1));
    $spacePos  = strpos($productName, ' ');
    $brandName = strtoupper($spacePos !== false ? trim(substr($productName, $spacePos)) : $productName);
?>
  <a href="index.php" class="nav-brand">
    <span class="nav-brand-mark"><?= htmlspecialchars($brandMark) ?></span>
    <span class="nav-brand-name"><?= htmlspecialchars($brandName) ?></span>
  </a>
  <div class="nav-right" x-show="user">
    <div class="nav-user-info">
      <span class="nav-user-name" x-text="user?.name"></span>
      <span class="badge" :class="user?.role === 'admin' ? 'badge-admin' : 'badge-user'" x-text="user?.role"></span>
    </div>
    <button class="btn-nav-logout" @click="logout()">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none"><path d="M6 2H3a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3M10.5 11 14 8m0 0-3.5-3M14 8H6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
      Sign out
    </button>
  </div>
</nav>

<!-- ── LAYOUT ── -->
<div class="app-layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <nav class="sidebar-nav">
      <button class="nav-item" :class="{ active: view === 'dashboard' }" @click="setView('dashboard')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="9" width="5.5" height="5.5" rx="1.5" stroke="currentColor" stroke-width="1.3"/></svg>
        <span class="nav-item-label">Dashboard</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'framework' }" @click="setView('framework')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M2 8h8M2 12h10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        <span class="nav-item-label">Framework</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'changes' }" @click="setView('changes')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a2.1 2.1 0 0 1 2.97 2.97L5.5 14.5l-4 1 1-4 9-9z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
        <span class="nav-item-label">Changes</span>
        <span class="nav-badge" :class="{ zero: pendingCount === 0 }" x-text="pendingCount"></span>
      </button>
      <button class="nav-item" :class="{ active: view === 'submissions' }" @click="setView('submissions')" x-show="user?.role === 'admin'">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M14 10V12.67A1.33 1.33 0 0 1 12.67 14H3.33A1.33 1.33 0 0 1 2 12.67V10M8 2v8M5 5l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="nav-item-label">Submissions</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'releases' }" @click="setView('releases')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M4.5 1h7L14 4.5V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h.5" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M4.5 1v4h7" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
        <span class="nav-item-label">Releases</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'committee' }" @click="setView('committee')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="5.5" cy="4.5" r="2" stroke="currentColor" stroke-width="1.3"/><circle cx="10.5" cy="4.5" r="2" stroke="currentColor" stroke-width="1.3"/><path d="M1 13c0-2.5 2-4 4.5-4s4.5 1.5 4.5 4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M10 9c2 0 4 1.2 4 3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        <span class="nav-item-label">Committee</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'users' }" @click="setView('users')" x-show="user?.role === 'admin'">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5.5" r="2.75" stroke="currentColor" stroke-width="1.3"/><path d="M2 14c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        <span class="nav-item-label">Users</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'settings' }" @click="setView('settings')" x-show="user?.role === 'admin'">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3.05 3.05l1.41 1.41M11.54 11.54l1.41 1.41M3.05 12.95l1.41-1.41M11.54 4.46l1.41-1.41" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        <span class="nav-item-label">Settings</span>
      </button>
      <button class="nav-item" :class="{ active: view === 'logs' }" @click="setView('logs')" x-show="user?.role === 'admin'">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M2 3h12M2 6.5h8M2 10h10M2 13.5h6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        <span class="nav-item-label">Logs</span>
      </button>
    </nav>
    <div class="sidebar-divider"></div>
    <div class="sidebar-workflow">
      <div class="sidebar-workflow-steps">
        © Amaury Lesplingart <?= date('Y') ?> <br> Made in 🇧🇪 with ❤️
        <div style="margin-top:6px">
          <?php if ($instanceSourceUrl !== ''): ?>
            <a href="<?= htmlspecialchars($instanceSourceUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--text3);text-decoration:underline">This instance's source (AGPL-3.0)</a><br>
            <a href="<?= htmlspecialchars(APP_SOURCE_URL) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--text3);text-decoration:underline;font-size:.9em">Based on Framework Manager</a>
          <?php else: ?>
            <a href="<?= htmlspecialchars(APP_SOURCE_URL) ?>" target="_blank" rel="noopener noreferrer" style="color:var(--text3);text-decoration:underline">Source code (AGPL-3.0)</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="main-content">

<?php if ($setupStillPresent): ?>
    <!-- Security warning: one-time setup script still present -->
    <div style="display:flex;align-items:flex-start;gap:12px;background:var(--danger-soft,rgba(255,59,48,.08));border:1px solid rgba(255,59,48,.35);border-radius:10px;padding:14px 16px;margin-bottom:20px">
      <svg width="18" height="18" viewBox="0 0 16 16" fill="none" style="flex-shrink:0;margin-top:1px;color:#c22"><path d="M8 1.5 15 14H1L8 1.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 6v3.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><circle cx="8" cy="11.5" r=".8" fill="currentColor"/></svg>
      <div style="font-size:.8125rem;line-height:1.55;color:var(--text1)">
        <strong>Security: the setup script is still on the server.</strong>
        Setup is complete, but <code>setup.php</code> still exists. It is disabled (it redirects away once installed), but you should delete it. Run:
        <div style="margin-top:6px"><code style="user-select:all;background:var(--bg-offset,#f2f2f4);padding:2px 6px;border-radius:4px">rm <?= htmlspecialchars(__DIR__) ?>/setup.php</code></div>
      </div>
    </div>
<?php endif; ?>

    <!-- Loading -->
    <div class="page-loader" x-show="loading">
      <div class="spinner"></div>
      <span style="font-size:.875rem; color:var(--text3)">Loading…</span>
    </div>

    <!-- ════ DASHBOARD ════ -->
    <div x-show="!loading && view === 'dashboard'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Dashboard</h1>
          <p class="page-subtitle">Overview of the <?= htmlspecialchars($fwName) ?> framework working copy</p>
        </div>
      </div>

      <!-- Stats: one section per sub-framework (driven by the active profile) -->
      <template x-for="(sf, slug) in profile.subframeworks" :key="slug">
        <div>
          <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px">
            <span style="font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--text3)" x-text="sf.label"></span>
            <span style="flex:1; height:1px; background:var(--border)"></span>
          </div>
          <div class="stats-grid" style="margin-bottom:20px">
            <template x-for="lvl in levelKeys" :key="lvl">
              <div class="stat-card">
                <div class="stat-icon" :style="'background:' + levelTint(lvl) + ';color:' + levelColor(lvl)">
                  <svg width="18" height="18" viewBox="0 0 16 16" fill="none"><rect x="2" y="2" width="12" height="12" rx="2.5" stroke="currentColor" stroke-width="1.3"/><path d="M2 6h12" stroke="currentColor" stroke-width="1.3"/></svg>
                </div>
                <div class="stat-value" x-text="(dashboardStats[slug] && dashboardStats[slug][lvl]) || 0"></div>
                <div class="stat-label" x-text="(sf.levels[lvl] && sf.levels[lvl].plural) || lvl"></div>
              </div>
            </template>
          </div>
        </div>
      </template>

      <!-- Stats: Workflow -->
      <div class="stats-grid">
        <div class="stat-card" :class="{ highlight: pendingCount > 0 }">
          <div class="stat-icon" style="background:var(--warn-soft); color:var(--warn)">
            <svg width="18" height="18" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 5v3.5l2 1.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </div>
          <div class="stat-value" x-text="pendingCount"></div>
          <div class="stat-label">Pending Changes</div>
        </div>
      </div>

      <!-- Dashboard body -->
      <div class="dashboard-grid">

        <!-- Recent changes -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Recent Changes</span>
            <button class="btn btn-secondary btn-sm" @click="setView('changes')">View all</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Type</th>
                  <th>Author</th>
                  <th>Status</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <template x-for="change in changes.slice(0, 5)" :key="change.id">
                  <tr>
                    <td>
                      <span class="badge-id" x-text="change.framework_id || '—'"></span>
                    </td>
                    <td><span class="badge" :class="'badge-' + change.type" x-text="change.type"></span></td>
                    <td class="text-sm" x-text="change.author_name"></td>
                    <td><span class="badge" :class="'badge-' + change.status" x-text="change.status"></span></td>
                    <td class="text-xs text-muted" x-text="formatDate(change.created_at)"></td>
                  </tr>
                </template>
                <tr x-show="changes.length === 0">
                  <td colspan="5" style="text-align:center; color:var(--text3); padding:28px 16px; font-size:.875rem">No changes yet</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- How it works -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">How this works</span>
          </div>
          <div class="card-body">
            <div class="workflow-steps">
              <div class="workflow-step">
                <div class="workflow-step-icon" style="background:var(--accent-soft); color:var(--accent)">
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a2.1 2.1 0 0 1 2.97 2.97L5.5 14.5l-4 1 1-4 9-9z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                </div>
                <div>
                  <div class="workflow-step-title">Propose a change</div>
                  <div class="workflow-step-desc">Edit or add any item in the framework. Your change is queued for review.</div>
                </div>
              </div>
              <div class="workflow-step">
                <div class="workflow-step-icon" style="background:rgba(88,86,214,.06); color:#5856D6">
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="5.5" r="2.75" stroke="currentColor" stroke-width="1.3"/><path d="M2 14c0-3.3 2.7-5.5 6-5.5s6 2.2 6 5.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
                </div>
                <div>
                  <div class="workflow-step-title">Peer reviews it</div>
                  <div class="workflow-step-desc">A team member reviews the diff and approves or rejects with a comment.</div>
                </div>
              </div>
              <div class="workflow-step">
                <div class="workflow-step-icon" style="background:rgba(52,199,89,.07); color:#1a7f37">
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div>
                  <div class="workflow-step-title">Applied to framework</div>
                  <div class="workflow-step-desc">Approved changes are written to the live working bundle immediately.</div>
                </div>
              </div>
            </div>
            <div style="margin-top:18px" class="info-box">
              Signed in as <strong x-text="user?.name"></strong>
              <span class="badge" :class="user?.role === 'admin' ? 'badge-admin' : 'badge-user'" x-text="user?.role" style="margin-left:4px"></span>
              &mdash;
              <span x-text="user?.role === 'admin' ? 'Can propose, review, release, and manage users.' : 'Can propose changes and review others\' proposals.'"></span>
            </div>
          </div>
        </div>

      </div>
    </div><!-- /dashboard -->

    <!-- ════ FRAMEWORK ════ -->
    <div x-show="!loading && view === 'framework'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Framework</h1>
          <p class="page-subtitle" x-text="'Browse ' + frameworkLabelPlural('tactic').toLowerCase() + ' and ' + frameworkLabelPlural('technique').toLowerCase() + ' in the working ' + profile.framework_name + ' STIX bundle'"></p>
        </div>
      </div>

      <div class="tabs" style="margin-bottom:16px">
        <template x-for="(sf, slug) in profile.subframeworks" :key="slug">
          <button class="tab-btn" :class="{ active: activeFramework === slug }" @click="switchFramework(slug)" x-text="sf.label"></button>
        </template>
      </div>

      <div class="framework-layout">

        <!-- Tactic list -->
        <div class="tactic-list">
          <div class="tactic-list-header">
            <div class="tactic-list-title">
              <span x-text="frameworkLabelPlural('tactic')"></span>
              <span class="count-chip" x-text="tactics.length"></span>
            </div>
            <button class="btn btn-primary btn-xs" @click="openAddModal('tactic')">
              <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
              Add
            </button>
          </div>
          <template x-for="tactic in tactics" :key="tactic.stix_id">
            <div
              class="tactic-item"
              :class="{ active: selectedTacticId === tactic.stix_id, deprecated: tactic.deprecated }"
              @click="selectTactic(tactic)"
            >
              <span class="tactic-framework-id" x-text="tactic.framework_id"></span>
              <div class="tactic-info">
                <div class="tactic-name" x-text="tactic.name" :style="tactic.deprecated ? 'text-decoration:line-through; opacity:.5' : ''"></div>
                <span x-show="tactic.deprecated" style="font-size:.625rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--text3); opacity:.7">Deprecated<template x-if="tactic.replaced_by"><span style="font-weight:400; text-transform:none; letter-spacing:0"> &rarr; <span x-text="tactic.replaced_by" style="font-weight:600; color:var(--accent); opacity:1"></span></span></template></span>
                <template x-if="pendingChangeFor(tactic.stix_id)">
                  <span style="font-size:.625rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--warn-text, #9a6700); white-space:nowrap"><span x-text="pendingChangeFor(tactic.stix_id).type === 'delete' ? 'pending deprecation' : 'pending edit'"></span></span>
                </template>
              </div>
              <span class="tactic-tech-count-num" x-text="getTechniqueCountForTactic(tactic.shortname)"></span>
            </div>
          </template>
          <!-- Pending new tactics -->
          <template x-for="ch in pendingAddTactics()" :key="ch.id">
            <div
              class="tactic-item"
              style="background:var(--warn-bg, #fff8e1); border-left:3px solid var(--warn-border, #f5c518); cursor:pointer"
              @click="openReviewModal(ch)"
            >
              <span class="tactic-framework-id" style="opacity:.6" x-text="ch.preview_framework_id || '—'"></span>
              <div class="tactic-info">
                <div class="tactic-name" x-text="ch.after?.name || ('New ' + frameworkLabel('tactic').toLowerCase())"></div>
                <span style="font-size:.625rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--warn-text, #9a6700); white-space:nowrap">Pending addition</span>
              </div>
            </div>
          </template>
        </div>

        <!-- Technique panel -->
        <div class="technique-panel">

          <!-- No tactic selected -->
          <template x-if="!selectedTacticId">
            <div class="empty-state">
              <svg width="44" height="44" viewBox="0 0 44 44" fill="none"><rect x="4" y="4" width="16" height="16" rx="3.5" stroke="currentColor" stroke-width="1.4"/><rect x="24" y="4" width="16" height="16" rx="3.5" stroke="currentColor" stroke-width="1.4"/><rect x="4" y="24" width="16" height="16" rx="3.5" stroke="currentColor" stroke-width="1.4"/><rect x="24" y="24" width="16" height="16" rx="3.5" stroke="currentColor" stroke-width="1.4"/></svg>
              <div class="empty-state-title" x-text="'Select a ' + frameworkLabel('tactic').toLowerCase()"></div>
              <div class="empty-state-sub" x-text="'Choose a ' + frameworkLabel('tactic').toLowerCase() + ' from the left to view its ' + frameworkLabelPlural('technique').toLowerCase()"></div>
            </div>
          </template>

          <!-- Tactic selected -->
          <template x-if="selectedTacticId">
            <div style="display:flex; flex-direction:column; flex:1; min-height:0">
              <div class="technique-panel-header">
                <div>
                  <div class="technique-panel-heading" x-text="selectedTactic?.name"></div>
                  <div class="technique-panel-sub" x-text="selectedTactic?.framework_id"></div>
                </div>
                <div class="technique-panel-actions">
                  <button class="btn btn-secondary btn-sm" @click="openEditModal(selectedTactic, 'tactic')">
                    <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M11.5 2.5a2.1 2.1 0 0 1 2.97 2.97L5.5 14.5l-4 1 1-4 9-9z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/></svg>
                    <span x-text="'Edit ' + frameworkLabel('tactic')"></span>
                  </button>
                  <button class="btn btn-danger btn-sm" @click="openDeleteModal(selectedTactic, 'tactic')">
                    <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 9h8l1-9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Delete
                  </button>
                  <button class="btn btn-primary btn-sm" @click="openAddModal('technique')">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    <span x-text="'Add ' + frameworkLabel('technique')"></span>
                  </button>
                </div>
              </div>

              <!-- Loading techniques -->
              <div class="page-loader" x-show="loadingTechniques" style="min-height:160px">
                <div class="spinner"></div>
              </div>

              <!-- Technique list -->
              <template x-if="!loadingTechniques">
                <div style="flex:1; overflow-y:auto">
                  <template x-if="groupedTechniques.length === 0 && pendingAddTechniques(selectedTactic?.shortname).length === 0">
                    <div class="empty-state">
                      <svg width="38" height="38" viewBox="0 0 38 38" fill="none"><rect x="3" y="7" width="32" height="24" rx="3.5" stroke="currentColor" stroke-width="1.4"/><path d="M13 19h12M19 13v12" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                      <div class="empty-state-title" x-text="'No ' + frameworkLabel('technique').toLowerCase() + 's yet'"></div>
                      <div class="empty-state-sub" x-text="'Add the first ' + frameworkLabel('technique').toLowerCase() + ' to this ' + frameworkLabel('tactic').toLowerCase()"></div>
                      <button class="btn btn-primary btn-sm" @click="openAddModal('technique')" x-text="'Add ' + frameworkLabel('technique').toLowerCase()"></button>
                    </div>
                  </template>
                  <template x-if="groupedTechniques.length > 0 || pendingAddTechniques(selectedTactic?.shortname).length > 0">
                    <div class="technique-list">
                      <template x-for="group in groupedTechniques" :key="group.parent.stix_id">
                        <div class="technique-group">
                          <!-- Parent technique row -->
                          <div class="technique-row" :class="{ 'technique-deprecated': group.parent.deprecated }" @click.stop>
                            <div class="technique-row-id" style="display:flex; align-items:center; gap:4px">
                              <template x-if="group.children.length > 0 || pendingAddSubTechniques(group.parent.framework_id).length > 0">
                                <button class="btn-expand" @click.stop="expandedTechniques[group.parent.framework_id] = !expandedTechniques[group.parent.framework_id]" :title="expandedTechniques[group.parent.framework_id] ? 'Collapse' : 'Expand'" style="background:none; border:none; cursor:pointer; padding:2px; color:var(--text3); font-size:.75rem; line-height:1; flex-shrink:0">
                                  <span x-text="expandedTechniques[group.parent.framework_id] ? '▼' : '▶'" style="font-size:.6rem"></span>
                                </button>
                              </template>
                              <span class="badge-id" x-text="group.parent.framework_id" :style="group.parent.deprecated ? 'opacity:.45' : ''"></span>
                              <template x-if="group.children.length > 0">
                                <span style="font-size:.625rem; color:var(--text3); font-weight:500" x-text="'[' + group.children.length + ']'"></span>
                              </template>
                              <template x-if="pendingChangeFor(group.parent.stix_id)">
                                <span style="display:inline-flex; align-items:center; gap:3px; font-size:.625rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--warn-text, #9a6700); background:var(--warn-bg, #fff8e1); border:1px solid var(--warn-border, #f5c518); border-radius:var(--r1); padding:1px 6px; margin-left:4px; cursor:pointer; white-space:nowrap" @click.stop="openReviewModal(pendingChangeFor(group.parent.stix_id))" title="Click to review"><span x-text="pendingChangeFor(group.parent.stix_id).type === 'delete' ? 'pending deprecation' : 'pending edit'"></span></span>
                              </template>
                            </div>
                            <div class="technique-row-body">
                              <div class="technique-row-name" x-text="group.parent.name" :style="group.parent.deprecated ? 'text-decoration:line-through; opacity:.45' : ''"></div>
                              <div class="technique-row-desc" x-text="group.parent.description" :style="group.parent.deprecated ? 'opacity:.35' : ''"></div>
                              <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:4px" x-show="(group.parent.related_reports?.length || 0) + (group.parent.related_techniques?.length || 0) > 0">
                                <template x-if="group.parent.related_reports?.length > 0">
                                  <span style="display:inline-flex; align-items:center; gap:3px; font-size:.6875rem; color:var(--text3); padding:2px 7px; background:var(--bg2); border-radius:var(--r1); border:1px solid var(--border)">
                                    <svg width="10" height="10" viewBox="0 0 16 16" fill="none"><path d="M4 1v14l4-3 4 3V1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                                    <span x-text="group.parent.related_reports.length + ' report' + (group.parent.related_reports.length !== 1 ? 's' : '')"></span>
                                  </span>
                                </template>
                                <template x-for="rel in (group.parent.related_techniques || []).slice(0, 4)" :key="rel.stix_id">
                                  <span style="display:inline-flex; align-items:center; gap:3px; font-size:.6875rem; padding:2px 7px; background:var(--bg2); border-radius:var(--r1); border:1px solid var(--border); color:var(--accent); font-family:var(--font-mono, monospace); font-weight:600" :title="rel.name">
                                    <span x-show="frameworkBadgeLabel(rel.framework_id) && frameworkBadgeLabel(rel.framework_id) !== activeBadgeLabel()" :style="'font-size:.5625rem; font-weight:700; letter-spacing:.03em; padding:0 3px; border-radius:2px; color:#fff; background:' + frameworkBadgeColor(rel.framework_id)" x-text="frameworkBadgeLabel(rel.framework_id)"></span>
                                    <span x-text="rel.framework_id"></span>
                                  </span>
                                </template>
                                <template x-if="(group.parent.related_techniques || []).length > 4">
                                  <span style="font-size:.6875rem; color:var(--text3); padding:2px 4px" x-text="'+' + (group.parent.related_techniques.length - 4) + ' more'"></span>
                                </template>
                              </div>
                            </div>
                            <div class="technique-row-actions">
                              <template x-if="!group.parent.deprecated">
                                <button class="btn btn-secondary btn-xs" @click.stop="openAddModal('subtechnique', group.parent)" x-text="'+ ' + frameworkLabel('subtechnique')">+ Sub</button>
                              </template>
                              <template x-if="!group.parent.deprecated">
                                <button class="btn btn-secondary btn-xs" @click.stop="openEditModal(group.parent, 'technique')">Edit</button>
                              </template>
                              <template x-if="!group.parent.deprecated">
                                <button class="btn btn-danger btn-xs" @click.stop="openDeleteModal(group.parent, 'technique')">Deprecate</button>
                              </template>
                              <template x-if="group.parent.deprecated">
                                <span style="font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.055em; color:var(--text3); opacity:.6; padding:0 4px">Deprecated<template x-if="group.parent.replaced_by"><span style="font-weight:400; text-transform:none; letter-spacing:0"> &rarr; <span x-text="group.parent.replaced_by" style="font-weight:600; color:var(--accent); opacity:1"></span></span></template></span>
                              </template>
                            </div>
                          </div>
                          <!-- Expanded sub-techniques -->
                          <template x-if="expandedTechniques[group.parent.framework_id]">
                            <div style="margin-left:24px; border-left:2px solid var(--border); padding-left:8px">
                              <template x-for="child in group.children" :key="child.stix_id">
                                <div class="technique-row" :class="{ 'technique-deprecated': child.deprecated }" style="padding:6px 10px; font-size:.875rem" @click.stop>
                                  <div class="technique-row-id">
                                    <span style="color:var(--text3); font-size:.6875rem; margin-right:2px">·</span>
                                    <span class="badge-id" style="font-size:.75rem" x-text="child.framework_id" :style="child.deprecated ? 'opacity:.45' : ''"></span>
                                    <template x-if="pendingChangeFor(child.stix_id)">
                                      <span style="display:inline-flex; align-items:center; gap:3px; font-size:.575rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--warn-text, #9a6700); background:var(--warn-bg, #fff8e1); border:1px solid var(--warn-border, #f5c518); border-radius:var(--r1); padding:1px 5px; margin-left:3px; cursor:pointer; white-space:nowrap" @click.stop="openReviewModal(pendingChangeFor(child.stix_id))" title="Click to review"><span x-text="pendingChangeFor(child.stix_id).type === 'delete' ? 'pending deprecation' : 'pending edit'"></span></span>
                                    </template>
                                  </div>
                                  <div class="technique-row-body">
                                    <div class="technique-row-name" style="font-size:.8125rem" x-text="child.name" :style="child.deprecated ? 'text-decoration:line-through; opacity:.45' : ''"></div>
                                    <div class="technique-row-desc" style="font-size:.75rem" x-text="child.description" :style="child.deprecated ? 'opacity:.35' : ''"></div>
                                  </div>
                                  <div class="technique-row-actions">
                                    <template x-if="!child.deprecated">
                                      <button class="btn btn-secondary btn-xs" @click.stop="openEditModal(child, 'subtechnique')">Edit</button>
                                    </template>
                                    <template x-if="!child.deprecated">
                                      <button class="btn btn-danger btn-xs" @click.stop="openDeleteModal(child, 'subtechnique')">Deprecate</button>
                                    </template>
                                    <template x-if="child.deprecated">
                                      <span style="font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.055em; color:var(--text3); opacity:.6; padding:0 4px">Deprecated</span>
                                    </template>
                                  </div>
                                </div>
                              </template>
                              <!-- Pending new sub-techniques -->
                              <template x-for="ch in pendingAddSubTechniques(group.parent.framework_id)" :key="ch.id">
                                <div class="technique-row" style="background:var(--warn-bg, #fff8e1); border-left:3px solid var(--warn-border, #f5c518); cursor:pointer; padding:6px 10px; font-size:.875rem" @click.stop="openReviewModal(ch)">
                                  <div class="technique-row-id">
                                    <span style="color:var(--text3); font-size:.6875rem; margin-right:2px">·</span>
                                    <span class="badge-id" style="font-size:.75rem; opacity:.6" x-text="ch.preview_framework_id || '—'"></span>
                                    <span style="display:inline-flex; align-items:center; gap:3px; font-size:.575rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--warn-text, #9a6700); background:var(--warn-bg, #fff8e1); border:1px solid var(--warn-border, #f5c518); border-radius:var(--r1); padding:1px 5px; margin-left:3px; white-space:nowrap">Pending addition</span>
                                  </div>
                                  <div class="technique-row-body">
                                    <div class="technique-row-name" style="font-size:.8125rem" x-text="ch.after?.name || ('New ' + frameworkLabel('subtechnique').toLowerCase())"></div>
                                    <div class="technique-row-desc" style="font-size:.75rem" x-text="ch.after?.description || ''"></div>
                                  </div>
                                </div>
                              </template>
                            </div>
                          </template>
                        </div>
                      </template>
                      <!-- Pending new techniques -->
                      <template x-for="ch in pendingAddTechniques(selectedTactic?.shortname)" :key="ch.id">
                        <div class="technique-group">
                          <div class="technique-row" style="background:var(--warn-bg, #fff8e1); border-left:3px solid var(--warn-border, #f5c518); cursor:pointer" @click.stop="openReviewModal(ch)">
                            <div class="technique-row-id">
                              <span class="badge-id" style="opacity:.6" x-text="ch.preview_framework_id || '—'"></span>
                              <span style="display:inline-flex; align-items:center; gap:3px; font-size:.625rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; color:var(--warn-text, #9a6700); background:var(--warn-bg, #fff8e1); border:1px solid var(--warn-border, #f5c518); border-radius:var(--r1); padding:1px 6px; margin-left:4px; white-space:nowrap">Pending addition</span>
                            </div>
                            <div class="technique-row-body">
                              <div class="technique-row-name" x-text="ch.after?.name || ('New ' + frameworkLabel('technique').toLowerCase())"></div>
                              <div class="technique-row-desc" x-text="ch.after?.description || ''"></div>
                            </div>
                          </div>
                        </div>
                      </template>
                    </div>
                  </template>
                </div>
              </template>
            </div>
          </template>

        </div>
      </div>
    </div><!-- /framework -->

    <!-- ════ CHANGES ════ -->
    <div x-show="!loading && view === 'changes'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Change Proposals</h1>
          <p class="page-subtitle">Changes require peer approval before being applied to the framework</p>
        </div>
      </div>

      <div class="tabs">
        <button class="tab-btn" :class="{active: changesFilter === 'all'}" @click="changesFilter = 'all'">
          All
          <span class="tab-count" x-text="changes.length"></span>
        </button>
        <button class="tab-btn" :class="{active: changesFilter === 'pending'}" @click="changesFilter = 'pending'">
          Pending
          <span class="tab-count" x-text="changes.filter(c=>c.status==='pending').length"></span>
        </button>
        <button class="tab-btn" :class="{active: changesFilter === 'approved'}" @click="changesFilter = 'approved'">
          Approved
          <span class="tab-count" x-text="changes.filter(c=>c.status==='approved').length"></span>
        </button>
        <button class="tab-btn" :class="{active: changesFilter === 'rejected'}" @click="changesFilter = 'rejected'">
          Rejected
          <span class="tab-count" x-text="changes.filter(c=>c.status==='rejected').length"></span>
        </button>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Type</th>
                <th>Author</th>
                <th>Date</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template x-for="change in filteredChanges" :key="change.id">
                <tr>
                  <td>
                    <div style="display:flex; align-items:center; gap:8px">
                      <span class="badge-id" x-text="change.framework_id || '—'"></span>
                      <span class="badge" :class="'badge-' + change.target_type" x-text="change.target_type" style="font-size:.625rem"></span>
                    </div>
                  </td>
                  <td>
                    <span class="badge" :class="'badge-' + change.type" x-text="change.type"></span>
                  </td>
                  <td class="text-sm" x-text="change.author_name"></td>
                  <td class="text-xs text-muted" x-text="formatDate(change.created_at)"></td>
                  <td><span class="badge" :class="'badge-' + change.status" x-text="change.status"></span></td>
                  <td style="white-space:nowrap">
                    <div style="display:flex; gap:4px; justify-content:flex-end">
                      <button class="btn btn-secondary btn-sm" @click="openReviewModal(change)"
                        x-text="change.status === 'pending' && change.author_id !== user?.id ? 'Review' : 'View'">
                      </button>
                      <template x-if="change.status === 'pending' && change.author_id === user?.id">
                        <button class="btn btn-danger btn-sm" @click="withdrawChange(change)">Withdraw</button>
                      </template>
                    </div>
                  </td>
                </tr>
              </template>
              <tr x-show="filteredChanges.length === 0">
                <td colspan="6" style="text-align:center; color:var(--text3); padding:40px 16px; font-size:.875rem">
                  No changes found
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /changes -->

    <!-- ════ RELEASES ════ -->
    <div x-show="!loading && view === 'releases'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Releases</h1>
          <p class="page-subtitle">Versioned snapshots of the <?= htmlspecialchars($fwName) ?> working bundle</p>
        </div>
        <div class="page-header-actions" x-show="user?.role === 'admin'" style="display:flex;gap:8px">
          <button class="btn btn-primary" @click="releaseForm.open = true; releaseForm.result = null; releaseForm.bumpType = 'patch'; releaseForm.version = computeNextVersion('patch'); releaseForm.name = latestReleaseName()">
            <svg width="13" height="13" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            Create Release
          </button>
        </div>
      </div>

      <div class="info-box mb-16" x-show="user?.role === 'admin' && publishStatus.unpublished_count > 0" x-cloak style="margin-bottom:16px">
        <strong x-text="publishStatus.unpublished_count"></strong> approved
        <span x-text="publishStatus.unpublished_count === 1 ? 'change' : 'changes'"></span> ready to publish.
        <template x-if="publishStatus.last_published_at">
          <span>Last published <span x-text="formatDate(publishStatus.last_published_at)"></span>.</span>
        </template>
      </div>

      <div x-show="releases.length === 0" class="empty-state" style="min-height:280px">
        <svg width="44" height="44" viewBox="0 0 44 44" fill="none"><path d="M4.5 13h7L14 4.5V22a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1h.5" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M22 4l5 9h10l-8 5.5 3 9.5L22 22l-10 6 3-9.5L7 13h10z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
        <div class="empty-state-title">No releases yet</div>
        <div class="empty-state-sub">Create the first versioned snapshot of the framework</div>
        <button class="btn btn-primary btn-sm" @click="releaseForm.open = true; releaseForm.result = null; releaseForm.bumpType = 'patch'; releaseForm.version = computeNextVersion('patch'); releaseForm.name = latestReleaseName()" x-show="user?.role === 'admin'">Create Release</button>
      </div>

      <div class="releases-list" x-show="releases.length > 0">
        <template x-for="rel in releases" :key="rel.id">
          <div class="release-card">
            <div class="release-card-main">
              <div class="release-version">v<span x-text="rel.version"></span></div>
              <div class="release-name" x-text="rel.name"></div>
              <div class="release-stats">
                <template x-for="(st, slug) in (rel.stats || {})" :key="slug">
                  <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:baseline" x-show="st && st.tactic !== undefined">
                    <span class="release-stat" style="font-weight:600" x-text="st.label || slug"></span>
                    <span class="release-stat"><strong x-text="st.tactic ?? '—'"></strong> <span x-text="((st.level_labels && st.level_labels.tactic) || 'tactics').toLowerCase()"></span></span>
                    <span class="release-stat"><strong x-text="st.technique ?? '—'"></strong> <span x-text="((st.level_labels && st.level_labels.technique) || 'techniques').toLowerCase()"></span></span>
                    <span class="release-stat"><strong x-text="st.subtechnique ?? '—'"></strong> <span x-text="((st.level_labels && st.level_labels.subtechnique) || 'sub-techniques').toLowerCase()"></span></span>
                  </div>
                </template>
              </div>
              <div x-show="rel.notes" class="release-notes" x-text="rel.notes"></div>
              <div class="release-meta">
                Created <span x-text="formatDate(rel.created_at)"></span> by <strong x-text="rel.created_by"></strong>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div><!-- /releases -->

    <!-- ════ USERS ════ -->
    <div x-show="!loading && view === 'users' && user?.role === 'admin'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Team</h1>
          <p class="page-subtitle">Manage team members and their access levels</p>
        </div>
        <div class="page-header-actions">
          <button class="btn btn-primary" @click="openUserModal('add')">
            <svg width="13" height="13" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
            Add User
          </button>
        </div>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Member</th>
                <th>Role</th>
                <th>Created</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template x-for="u in users" :key="u.id">
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="user-avatar" x-text="(u.name || u.username || '?').charAt(0)"></div>
                      <div class="user-cell-info">
                        <div class="user-full-name" x-text="u.name || '—'"></div>
                        <div class="user-username" x-text="'@' + u.username"></div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge" :class="'badge-' + u.role" x-text="u.role"></span></td>
                  <td class="text-xs text-muted" x-text="formatDate(u.created_at)"></td>
                  <td>
                    <div style="display:flex; gap:6px">
                      <button class="btn btn-secondary btn-sm" @click="openUserModal('edit', u)">Edit</button>
                      <button class="btn btn-danger btn-sm" @click="deleteUser(u)" x-show="u.id !== user?.id">Delete</button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /users -->

    <!-- ════ COMMITTEE ════ -->
    <div x-show="!loading && view === 'committee'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Technical Steering Committee</h1>
          <p class="page-subtitle">Members and governance of the <?= htmlspecialchars($fwName) ?> TSC</p>
        </div>
      </div>

      <div class="tabs">
        <button class="tab-btn" :class="{ active: committeeTab === 'members' }" @click="committeeTab = 'members'">Members</button>
        <button class="tab-btn" :class="{ active: committeeTab === 'guide' }" @click="committeeTab = 'guide'">Guide</button>
        <button class="tab-btn" :class="{ active: committeeTab === 'charter' }" @click="committeeTab = 'charter'">Charter</button>
        <button class="tab-btn" :class="{ active: committeeTab === 'conduct' }" @click="committeeTab = 'conduct'; if (!codeOfConductHtml) loadCodeOfConduct()">Code of Conduct</button>
      </div>

      <!-- Members tab -->
      <div x-show="committeeTab === 'members'" style="max-width:800px">

        <!-- President -->
        <template x-for="m in committeeMembers.filter(m => m.role === 'president')" :key="m.id">
          <div class="card" style="margin-bottom:16px">
            <div class="card-body" style="padding:24px;display:flex;align-items:center;gap:18px">
              <div class="committee-avatar president" x-text="m.name.split(' ').map(w => w[0]).join('')"></div>
              <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
                  <span style="font-size:1.0625rem;font-weight:600" x-text="m.name"></span>
                  <span class="badge badge-admin" x-text="m.title"></span>
                </div>
                <div style="font-size:.8125rem;color:var(--text2)" x-text="m.email"></div>
              </div>
            </div>
          </div>
        </template>

        <!-- Vice-Presidents -->
        <template x-if="committeeMembers.filter(m => m.role === 'vice-president').length > 0">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
            <template x-for="m in committeeMembers.filter(m => m.role === 'vice-president')" :key="m.id">
              <div class="card">
                <div class="card-body" style="padding:20px;display:flex;align-items:center;gap:14px">
                  <div class="committee-avatar vp" x-text="m.name.split(' ').map(w => w[0]).join('')"></div>
                  <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
                      <span style="font-size:.9375rem;font-weight:600" x-text="m.name"></span>
                      <span class="badge badge-approved" x-text="m.title" style="font-size:.6rem"></span>
                    </div>
                    <div style="font-size:.8125rem;color:var(--text2)" x-text="m.email"></div>
                  </div>
                </div>
              </div>
            </template>
          </div>
        </template>

        <!-- Members -->
        <template x-if="committeeMembers.filter(m => m.role === 'member').length > 0">
          <div class="card">
            <div class="card-header">
              <span class="card-title">Members</span>
              <span class="count-chip" x-text="committeeMembers.filter(m => m.role === 'member').length"></span>
            </div>
            <template x-for="m in committeeMembers.filter(m => m.role === 'member')" :key="m.id">
              <div style="display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border)">
                <div class="committee-avatar member" x-text="m.name.split(' ').map(w => w[0]).join('')"></div>
                <div style="flex:1;min-width:0">
                  <div style="font-size:.875rem;font-weight:500" x-text="m.name"></div>
                  <div style="font-size:.8125rem;color:var(--text2)" x-text="m.email"></div>
                </div>
              </div>
            </template>
          </div>
        </template>

        <!-- Empty state -->
        <template x-if="committeeMembers.length === 0">
          <div class="card">
            <div class="empty-state">
              <div class="empty-state-title">No committee members assigned</div>
              <div class="empty-state-sub">Assign TSC roles to users in the Users page.</div>
            </div>
          </div>
        </template>
      </div>

      <!-- Guide tab -->
      <div x-show="committeeTab === 'guide'" style="max-width:800px">

        <!-- Roles -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title">Roles</span>
          </div>
          <div class="card-body" style="padding:0">
            <div style="display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-bottom:1px solid var(--border)">
              <div style="flex-shrink:0;padding-top:2px">
                <span class="badge badge-admin">President / VP</span>
              </div>
              <div style="font-size:.8125rem;color:var(--text2);line-height:1.55">
                Full access. Can browse the framework, propose changes, review proposals from other members, create versioned releases, and publish to GitHub. Manages users, TSC roles, and settings.
              </div>
            </div>
            <div style="display:flex;align-items:flex-start;gap:14px;padding:16px 20px">
              <div style="flex-shrink:0;padding-top:2px">
                <span class="badge badge-user">Member</span>
              </div>
              <div style="font-size:.8125rem;color:var(--text2);line-height:1.55">
                Can browse the framework, propose changes (add, edit, deprecate framework objects), and review proposals from other members. Cannot create releases or publish.
              </div>
            </div>
          </div>
        </div>

        <!-- Workflow -->
        <div class="card" style="margin-bottom:16px">
          <div class="card-header">
            <span class="card-title">Workflow</span>
          </div>
          <div class="card-body" style="padding:24px">
            <div class="workflow-steps">
              <div class="workflow-step">
                <div class="workflow-step-num">1</div>
                <div>
                  <div class="workflow-step-title">Propose a change</div>
                  <div class="workflow-step-desc">Any member can propose adding a new framework object, editing an existing one, or deprecating one that is no longer relevant. Navigate to the <strong>Framework</strong> tab, select a category or impact, and use the action buttons.</div>
                </div>
              </div>
              <div class="workflow-step">
                <div class="workflow-step-num">2</div>
                <div>
                  <div class="workflow-step-title">Peer review</div>
                  <div class="workflow-step-desc">Proposals appear in the <strong>Changes</strong> tab. Any member other than the author can approve or reject a proposal. You cannot review your own changes — this ensures at least two people agree on every modification.</div>
                </div>
              </div>
              <div class="workflow-step">
                <div class="workflow-step-num">3</div>
                <div>
                  <div class="workflow-step-title">Approved changes are applied</div>
                  <div class="workflow-step-desc">Once approved, the change is immediately applied to the working framework. The framework always reflects the latest approved state.</div>
                </div>
              </div>
              <div class="workflow-step">
                <div class="workflow-step-num">4</div>
                <div>
                  <div class="workflow-step-title">Release and publish</div>
                  <div class="workflow-step-desc">When ready, a <strong>President or Vice-President</strong> creates a versioned release from the <strong>Releases</strong> tab. This snapshots the framework, generates YAML and documentation, translates content into configured languages, and opens a pull request on GitHub.</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Publishing details -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">What happens when you publish</span>
          </div>
          <div class="card-body" style="padding:24px;font-size:.8125rem;color:var(--text2);line-height:1.65">
            <p style="margin-bottom:12px">Publishing converts the framework into the repository format and pushes it to GitHub as a pull request on the <code style="font-size:.75rem;background:var(--bg2);padding:2px 6px;border-radius:4px">dev</code> branch:</p>
            <ul style="padding-left:20px;margin-bottom:12px">
              <li><strong>YAML files</strong> — one per framework object under <code style="font-size:.75rem;background:var(--bg2);padding:2px 6px;border-radius:4px">objects/</code></li>
              <li><strong>Documentation</strong> — Markdown files under <code style="font-size:.75rem;background:var(--bg2);padding:2px 6px;border-radius:4px">documentation/</code></li>
              <li><strong>Translations</strong> — auto-translated via DeepL (if configured) under <code style="font-size:.75rem;background:var(--bg2);padding:2px 6px;border-radius:4px">translations/</code></li>
              <li><strong>Changelog</strong> — lists all changes since the last publish</li>
              <li><strong>Schemas and CI</strong> — validation workflows and JSON schemas for the repository</li>
            </ul>
            <p style="margin-bottom:12px">Once the PR is merged and a GitHub Release is created, CI automatically builds downloadable artefacts: STIX 2.1 bundle, flat JSON, and CSV — in English and each translated language.</p>
          </div>
        </div>
      </div>

      <!-- Charter tab -->
      <div x-show="committeeTab === 'charter'" style="max-width:800px">
        <div class="card">
          <div class="card-body" style="padding:32px">
            <div x-show="!charterHtml" class="text-muted" style="text-align:center;padding:24px">Loading...</div>
            <div x-html="charterHtml" class="charter-content"></div>
          </div>
        </div>
      </div>

      <!-- Code of Conduct tab -->
      <div x-show="committeeTab === 'conduct'" style="max-width:800px">
        <div class="card">
          <div class="card-body" style="padding:32px">
            <div x-show="!codeOfConductHtml" class="text-muted" style="text-align:center;padding:24px">Loading...</div>
            <div x-html="codeOfConductHtml" class="charter-content"></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ════ SETTINGS ════ -->
    <div x-show="!loading && view === 'settings' && user?.role === 'admin'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Settings</h1>
          <p class="page-subtitle">Framework profile, GitHub App, and translation configuration</p>
        </div>
      </div>

      <div class="tabs">
        <button class="tab-btn" :class="{ active: settingsTab === 'profile' }" @click="settingsTab = 'profile'">Framework Profile</button>
        <button class="tab-btn" :class="{ active: settingsTab === 'github' }" @click="settingsTab = 'github'">
          GitHub
          <span :title="settingsConfigured('github') ? 'Configured' : 'Not configured'" :style="'display:inline-block;width:7px;height:7px;border-radius:50%;margin-left:6px;vertical-align:middle;background:' + (settingsConfigured('github') ? 'var(--check,#34c759)' : 'var(--text-muted,#b0b0b8)')"></span>
        </button>
        <button class="tab-btn" :class="{ active: settingsTab === 'translations' }" @click="settingsTab = 'translations'">
          Translations
          <span :title="settingsConfigured('translations') ? 'Enabled' : 'Disabled'" :style="'display:inline-block;width:7px;height:7px;border-radius:50%;margin-left:6px;vertical-align:middle;background:' + (settingsConfigured('translations') ? 'var(--check,#34c759)' : 'var(--text-muted,#b0b0b8)')"></span>
        </button>
        <button class="tab-btn" :class="{ active: settingsTab === 'public' }" @click="settingsTab = 'public'">
          Public API
          <span :title="settingsConfigured('public') ? 'Active' : 'Not configured'" :style="'display:inline-block;width:7px;height:7px;border-radius:50%;margin-left:6px;vertical-align:middle;background:' + (settingsConfigured('public') ? 'var(--check,#34c759)' : 'var(--text-muted,#b0b0b8)')"></span>
        </button>
        <button class="tab-btn" :class="{ active: settingsTab === 'governance' }" @click="settingsTab = 'governance'; loadGovernance()">Governance</button>
      </div>

      <!-- ══ Framework Profile tab ══ -->
      <div x-show="settingsTab === 'profile'" class="card" style="max-width:720px">
        <div class="card-body" style="padding:24px">
          <p class="page-subtitle" style="margin:0 0 20px 0">Defines everything specific to your framework — names, terminology, ID prefixes and technical identifiers. Fill in the readable names; the technical values can be auto-filled and IDs are generated for you.</p>

          <div class="form-group" x-show="profileAvailable.length">
            <label class="form-label">Start from a bundled profile</label>
            <div style="display:flex;gap:8px">
              <select class="form-input" x-model="profileImport" style="flex:1">
                <option value="">Select a profile…</option>
                <template x-for="p in profileAvailable" :key="p">
                  <option :value="p" x-text="p"></option>
                </template>
              </select>
              <button class="btn btn-secondary" @click="importProfile()" :disabled="!profileImport">Import</button>
            </div>
            <div class="text-xs text-muted" style="margin-top:4px">Importing replaces the current profile and reloads. Data for sub-frameworks not in the imported profile is permanently deleted.</div>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

          <!-- Identity -->
          <h2 style="font-size:.9375rem;font-weight:600;margin:0 0 12px">Names &amp; identity</h2>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Product name</label>
              <input class="form-input" type="text" x-model="profileForm.product_name" placeholder="Framework Manager">
              <div class="text-xs text-muted" style="margin-top:4px">Shown in the app title and top-left brand.</div>
            </div>
            <div class="form-group">
              <label class="form-label">Framework name</label>
              <input class="form-input" type="text" x-model="profileForm.framework_name" placeholder="e.g. My Framework">
              <div class="text-xs text-muted" style="margin-top:4px">The knowledge base this instance manages.</div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Organisation name</label>
              <input class="form-input" type="text" x-model="profileForm.org_name" placeholder="e.g. The Foundation">
            </div>
            <div class="form-group">
              <label class="form-label">Organisation short name</label>
              <input class="form-input" type="text" x-model="profileForm.org_short" placeholder="e.g. FW">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Organisation description</label>
            <textarea class="form-textarea" x-model="profileForm.org_description" rows="2" placeholder="One-sentence description of the organisation / framework"></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Base URL</label>
            <input class="form-input" type="text" x-model="profileForm.base_url" placeholder="https://example.com">
            <div class="text-xs text-muted" style="margin-top:4px">Used for schema, extension-definition and documentation links.</div>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:24px 0">

          <!-- Sub-frameworks -->
          <h2 style="font-size:.9375rem;font-weight:600;margin:0 0 4px">Sub-frameworks &amp; terminology</h2>
          <p class="text-xs text-muted" style="margin:0 0 16px">Add as many sub-frameworks as you need. Each has three fixed STIX levels — you only rebrand their display names. <strong>Removing a sub-framework permanently deletes all its stored data (tactics, techniques, sub-techniques and their change proposals) when you save. This cannot be undone.</strong></p>

          <template x-for="(sf, i) in profileForm.subs" :key="i">
            <div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:14px">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div style="font-size:.8125rem;font-weight:600">
                  <span x-text="sf.label || ('Sub-framework ' + (i + 1))"></span>
                  <span class="text-xs text-muted" style="font-weight:400" x-show="sf.slug" x-text="'· internal key: ' + sf.slug"></span>
                </div>
                <button class="btn btn-danger btn-sm" @click="removeSubframework(i)" x-show="profileForm.subs.length > 1">Remove</button>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label class="form-label">Sub-framework name</label>
                  <input class="form-input" type="text" x-model="sf.label" placeholder="e.g. Enterprise">
                </div>
                <div class="form-group">
                  <label class="form-label">ID prefix</label>
                  <input class="form-input" type="text" x-model="sf.id_prefix" placeholder="e.g. OBS" style="text-transform:uppercase">
                </div>
              </div>
              <div class="form-group">
                <label class="form-label">Kill-chain name</label>
                <input class="form-input" type="text" x-model="sf.kill_chain_name" placeholder="e.g. framework-primary">
                <div class="text-xs text-muted" style="margin-top:4px">STIX <code>kill_chain_name</code> — the key that separates this sub-framework's objects in stored data.</div>
              </div>
              <label class="form-label" style="margin-top:4px">Level names</label>
              <div class="text-xs text-muted" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:4px">
                <div class="form-group" style="margin-bottom:0">STIX role</div>
                <div class="form-group" style="margin-bottom:0">Singular</div>
                <div class="form-group" style="margin-bottom:0">Plural</div>
              </div>
              <template x-for="lvl in levelKeys" :key="lvl">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:8px;align-items:center">
                  <div class="form-group" style="margin-bottom:0">
                    <input class="form-input" type="text" :value="levelCanonical[lvl]" disabled title="Fixed STIX level — only the display name is brandable" style="background:var(--bg-offset,#f2f2f4);color:var(--text-muted)">
                  </div>
                  <div class="form-group" style="margin-bottom:0">
                    <input class="form-input" type="text" x-model="sf.levels[lvl].label" :placeholder="levelCanonical[lvl]">
                  </div>
                  <div class="form-group" style="margin-bottom:0">
                    <input class="form-input" type="text" x-model="sf.levels[lvl].plural" :placeholder="levelCanonical[lvl] + 's'">
                  </div>
                </div>
              </template>
            </div>
          </template>

          <button class="btn btn-secondary btn-sm" @click="addSubframework()">+ Add sub-framework</button>

          <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

          <!-- Advanced / technical -->
          <button @click="profileShowAdvanced = !profileShowAdvanced" style="background:none;border:none;cursor:pointer;font:inherit;font-size:.8125rem;font-weight:600;color:var(--text2);padding:0">
            <span x-text="profileShowAdvanced ? '▾' : '▸'"></span>&nbsp;Advanced (STIX &amp; technical identifiers)
          </button>
          <div x-show="profileShowAdvanced" x-cloak style="margin-top:14px">
            <div class="form-row">
              <div class="form-group">
                <label class="form-label">Source name</label>
                <input class="form-input" type="text" x-model="profileForm.source_name" placeholder="e.g. FRAMEWORK">
                <div class="text-xs text-muted" style="margin-top:4px">STIX <code>external_references.source_name</code>.</div>
              </div>
              <div class="form-group">
                <label class="form-label">Release artefact slug</label>
                <input class="form-input" type="text" x-model="profileForm.artifact_slug" placeholder="e.g. my-framework">
                <div class="text-xs text-muted" style="margin-top:4px">Filenames: <code x-text="(profileForm.artifact_slug || 'framework') + '-v1.0.0.stix.json'"></code></div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">STIX property prefix</label>
              <input class="form-input" type="text" x-model="profileForm.stix_property_prefix" placeholder="e.g. x_framework">
              <div class="text-xs text-muted" style="margin-top:4px" x-text="'Yields ' + (profileForm.stix_property_prefix || 'x_framework') + '_framework and ' + (profileForm.stix_property_prefix || 'x_framework') + '_version'"></div>
            </div>
            <div class="form-group">
              <label class="form-label">Extension-definition name</label>
              <input class="form-input" type="text" x-model="profileForm.extension_definition_name" placeholder="e.g. Framework Membership">
            </div>
            <div class="form-group">
              <label class="form-label">Extension-definition ID</label>
              <div style="display:flex;gap:8px">
                <input class="form-input" type="text" x-model="profileForm.extension_definition_id" placeholder="auto-generated" readonly style="font-family:monospace;font-size:.75rem">
                <button class="btn btn-secondary btn-sm" style="white-space:nowrap" @click="profileForm.extension_definition_id = genUuid('extension-definition')">Generate</button>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Identity reference</label>
              <div style="display:flex;gap:8px">
                <input class="form-input" type="text" x-model="profileForm.identity_ref" placeholder="auto-generated" readonly style="font-family:monospace;font-size:.75rem">
                <button class="btn btn-secondary btn-sm" style="white-space:nowrap" @click="profileForm.identity_ref = genUuid('identity')">Generate</button>
              </div>
              <div class="text-xs text-muted" style="margin-top:4px">STIX IDs are generated automatically — you don't need to edit these.</div>
            </div>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="btn btn-primary" @click="saveProfile()">Save Profile</button>
            <button class="btn btn-secondary" @click="deriveProfileTechnical()">Auto-fill blank technical fields</button>
            <span x-show="profileError" x-text="profileError" style="color:var(--danger,#d33);font-size:.8125rem"></span>
          </div>
        </div>
      </div>

      <!-- Source code (AGPL section 13) — deployment-level, kept out of the profile -->
      <div x-show="settingsTab === 'profile'" class="card" style="max-width:720px;margin-top:20px">
        <div class="card-body" style="padding:24px">
          <h2 style="font-size:1rem;font-weight:600;margin:0 0 6px">Source code (AGPL-3.0)</h2>
          <p class="page-subtitle" style="margin:0 0 16px 0">This application is licensed under the AGPL. A link to the upstream source is always shown and cannot be removed. If you run a <strong>modified</strong> version, section 13 requires you to offer <em>your</em> source to users: set the URL of your repository below and it will be shown as "This instance's source".</p>
          <div class="form-group">
            <label class="form-label">This instance's source URL <span class="text-muted" style="font-weight:400">(optional)</span></label>
            <input class="form-input" type="text" x-model="instanceSourceUrl" placeholder="https://github.com/you/your-fork">
            <div class="text-xs text-muted" style="margin-top:4px">Leave blank if you run the software unmodified. Upstream link (always shown): <a :href="upstreamSourceUrl" x-text="upstreamSourceUrl" target="_blank" rel="noopener" style="color:var(--text2)"></a></div>
          </div>
          <div style="display:flex;gap:10px;align-items:center">
            <button class="btn btn-primary" @click="saveInstanceSource()" :disabled="instanceSourceSaving">
              <span class="btn-spinner" x-show="instanceSourceSaving"></span> Save
            </button>
            <span x-show="instanceSourceMsg" x-text="instanceSourceMsg" style="color:var(--danger,#d33);font-size:.8125rem"></span>
          </div>
        </div>
      </div>

      <!-- ══ GitHub tab ══ -->
      <div x-show="settingsTab === 'github'" class="card" style="max-width:640px">
        <div class="card-body" style="padding:24px">

          <!-- GitHub App section -->
          <div style="display:flex;align-items:center;gap:8px;margin:0 0 16px 0">
            <h2 style="font-size:1rem;font-weight:600;margin:0">GitHub App</h2>
            <template x-if="publishStatus.github_configured">
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:500;color:var(--check);background:rgba(52,199,89,.1);padding:2px 8px;border-radius:99px">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M13.3 4.3a1 1 0 0 1 0 1.4l-6 6a1 1 0 0 1-1.4 0l-3-3a1 1 0 1 1 1.4-1.4L6.6 9.6l5.3-5.3a1 1 0 0 1 1.4 0z" fill="currentColor"/></svg>
                Connected
              </span>
            </template>
            <template x-if="!publishStatus.github_configured">
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:500;color:var(--text-muted);background:var(--bg-offset);padding:2px 8px;border-radius:99px">Not configured</span>
            </template>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">App ID</label>
              <input class="form-input" type="text" x-model="githubSettings.app_id" placeholder="123456">
            </div>
            <div class="form-group">
              <label class="form-label">Installation ID</label>
              <input class="form-input" type="text" x-model="githubSettings.installation_id" placeholder="12345678">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Repository Owner</label>
              <input class="form-input" type="text" x-model="githubSettings.owner" placeholder="e.g. my-org">
            </div>
            <div class="form-group">
              <label class="form-label">Repository Name</label>
              <input class="form-input" type="text" x-model="githubSettings.repo" placeholder="e.g. my-framework">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Private Key (PEM)</label>
            <textarea class="form-textarea" x-model="githubSettings.private_key" rows="6" placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;...&#10;-----END RSA PRIVATE KEY-----" style="font-family:monospace;font-size:.8125rem"></textarea>
            <template x-if="githubSettings.key_exists && !githubSettings.private_key">
              <div class="text-xs text-muted" style="margin-top:4px">Key file already configured. Paste a new key to replace it.</div>
            </template>
          </div>
          <div style="margin-top:8px">
            <button class="btn btn-secondary" @click="testGithubConnection()" :disabled="githubSettings.testing">
              <span class="btn-spinner" x-show="githubSettings.testing"></span>
              Test Connection
            </button>
          </div>
          <template x-if="githubSettings.test_result !== null">
            <div class="info-box" :style="githubSettings.test_result.success ? 'border-color:var(--check);background:rgba(52,199,89,.06)' : 'border-color:var(--danger);background:var(--danger-soft)'" style="margin-top:12px">
              <template x-if="githubSettings.test_result.success">
                <span>Connected to <strong x-text="githubSettings.test_result.repo_name"></strong></span>
              </template>
              <template x-if="!githubSettings.test_result.success">
                <span>Connection failed: <span x-text="githubSettings.test_result.error"></span></span>
              </template>
            </div>
          </template>

          <!-- Save (GitHub) -->
          <hr style="border:none;border-top:1px solid var(--border);margin:28px 0">
          <div style="display:flex;gap:10px">
            <button class="btn btn-primary" @click="saveGithubConfig()" :disabled="githubSettings.saving" style="flex:1">
              <span class="btn-spinner" x-show="githubSettings.saving"></span>
              Save Settings
            </button>
            <button class="btn btn-danger" @click="deleteConfigSection('github', 'GitHub')">Delete Settings</button>
          </div>
        </div>
      </div>

      <!-- ══ Translations tab ══ -->
      <div x-show="settingsTab === 'translations'" class="card" style="max-width:640px">
        <div class="card-body" style="padding:24px">

          <!-- Translations section -->
          <div style="display:flex;align-items:center;gap:8px;margin:0 0 4px 0">
            <h2 style="font-size:1rem;font-weight:600;margin:0">Translations</h2>
            <template x-if="githubSettings.deepl_key_exists && githubSettings.translation_languages.trim()">
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:500;color:var(--check);background:rgba(52,199,89,.1);padding:2px 8px;border-radius:99px">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M13.3 4.3a1 1 0 0 1 0 1.4l-6 6a1 1 0 0 1-1.4 0l-3-3a1 1 0 1 1 1.4-1.4L6.6 9.6l5.3-5.3a1 1 0 0 1 1.4 0z" fill="currentColor"/></svg>
                <span x-text="githubSettings.translation_languages.split(',').filter(s => s.trim()).map(s => s.trim().toUpperCase()).join(', ')"></span>
              </span>
            </template>
            <template x-if="!githubSettings.deepl_key_exists || !githubSettings.translation_languages.trim()">
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:500;color:var(--text-muted);background:var(--bg-offset);padding:2px 8px;border-radius:99px">Disabled</span>
            </template>
          </div>
          <p class="text-muted" style="font-size:.8125rem;margin:0 0 16px 0">Auto-translate framework content via DeepL when publishing</p>
          <div class="form-group">
            <label class="form-label">DeepL API Key</label>
            <input class="form-input" type="password" x-model="githubSettings.deepl_api_key" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx">
            <template x-if="githubSettings.deepl_key_exists && !githubSettings.deepl_api_key">
              <div class="text-xs text-muted" style="margin-top:4px">API key already configured. Paste a new key to replace it.</div>
            </template>
          </div>
          <div class="form-group">
            <label class="form-label">Target Languages</label>
            <input class="form-input" type="text" x-model="githubSettings.translation_languages" placeholder="fr,de,es">
            <div class="text-xs text-muted" style="margin-top:4px">Comma-separated DeepL language codes. Leave empty to disable translations.</div>
          </div>
          <div style="margin-top:8px">
            <button class="btn btn-secondary" @click="testDeeplConnection()" :disabled="githubSettings.deepl_testing">
              <span class="btn-spinner" x-show="githubSettings.deepl_testing"></span>
              Test DeepL
            </button>
          </div>
          <template x-if="githubSettings.deepl_test_result !== null">
            <div class="info-box" :style="githubSettings.deepl_test_result.success ? 'border-color:var(--check);background:rgba(52,199,89,.06)' : 'border-color:var(--danger);background:var(--danger-soft)'" style="margin-top:12px">
              <template x-if="githubSettings.deepl_test_result.success">
                <span>DeepL connected — &ldquo;Hello&rdquo; &rarr; &ldquo;<span x-text="githubSettings.deepl_test_result.sample"></span>&rdquo;</span>
              </template>
              <template x-if="!githubSettings.deepl_test_result.success">
                <span>DeepL test failed: <span x-text="githubSettings.deepl_test_result.error"></span></span>
              </template>
            </div>
          </template>

          <!-- Save (Translations) -->
          <hr style="border:none;border-top:1px solid var(--border);margin:28px 0">
          <div style="display:flex;gap:10px">
            <button class="btn btn-primary" @click="saveGithubConfig()" :disabled="githubSettings.saving" style="flex:1">
              <span class="btn-spinner" x-show="githubSettings.saving"></span>
              Save Settings
            </button>
            <button class="btn btn-danger" @click="deleteConfigSection('translations', 'Translations')">Delete Settings</button>
          </div>
        </div>
      </div>

      <!-- ══ Public API tab ══ -->
      <div x-show="settingsTab === 'public'" class="card" style="max-width:640px">
        <div class="card-body" style="padding:24px">

          <!-- Public API section -->
          <div style="display:flex;align-items:center;gap:8px;margin:0 0 4px 0">
            <h2 style="font-size:1rem;font-weight:600;margin:0">Public API</h2>
            <template x-if="githubSettings.public_api_token">
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:500;color:var(--check);background:rgba(52,199,89,.1);padding:2px 8px;border-radius:99px">
                <svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="M13.3 4.3a1 1 0 0 1 0 1.4l-6 6a1 1 0 0 1-1.4 0l-3-3a1 1 0 1 1 1.4-1.4L6.6 9.6l5.3-5.3a1 1 0 0 1 1.4 0z" fill="currentColor"/></svg>
                Active
              </span>
            </template>
            <template x-if="!githubSettings.public_api_token">
              <span style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;font-weight:500;color:var(--text-muted);background:var(--bg-offset);padding:2px 8px;border-radius:99px">Not configured</span>
            </template>
          </div>
          <p class="text-muted" style="font-size:.8125rem;margin:0 0 16px 0">Token for the public proposal form and member listing endpoints</p>
          <div class="form-group">
            <label class="form-label">API Token</label>
            <div style="display:flex;gap:8px">
              <input class="form-input" type="text" x-model="githubSettings.public_api_token" placeholder="Click Generate to create a token" readonly style="font-family:'SF Mono','Fira Code',monospace;font-size:.8125rem">
              <button class="btn btn-secondary btn-sm" style="white-space:nowrap" @click="githubSettings.public_api_token = [...crypto.getRandomValues(new Uint8Array(32))].map(b => b.toString(16).padStart(2, '0')).join('')">Generate</button>
            </div>
            <div class="text-xs text-muted" style="margin-top:4px">Use this token as <code>Authorization: Bearer &lt;token&gt;</code> in public API requests.</div>
          </div>

          <!-- Save (Public API) -->
          <hr style="border:none;border-top:1px solid var(--border);margin:28px 0">
          <div style="display:flex;gap:10px">
            <button class="btn btn-primary" @click="saveGithubConfig()" :disabled="githubSettings.saving" style="flex:1">
              <span class="btn-spinner" x-show="githubSettings.saving"></span>
              Save Settings
            </button>
            <button class="btn btn-danger" @click="deleteConfigSection('public_api', 'Public API')">Delete Settings</button>
          </div>

        </div>
      </div>

      <!-- ══ Governance tab ══ -->
      <div x-show="settingsTab === 'governance'" class="card" style="max-width:760px">
        <div class="card-body" style="padding:24px">
          <p class="page-subtitle" style="margin:0 0 20px 0">Edit the TSC Charter and Code of Conduct shown on the Committee page. Markdown is supported; <code>{{framework_name}}</code>, <code>{{org_name}}</code>, <code>{{product_name}}</code> and <code>{{org_short}}</code> are substituted from the profile when displayed.</p>

          <!-- Charter editor -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <h2 style="font-size:1rem;font-weight:600;margin:0">TSC Charter</h2>
            <div style="display:flex;gap:6px">
              <button class="btn btn-secondary btn-sm" :class="{ active: !gov.charterPreview }" @click="gov.charterPreview = false">Edit</button>
              <button class="btn btn-secondary btn-sm" :class="{ active: gov.charterPreview }" @click="gov.charterPreview = true">Preview</button>
            </div>
          </div>
          <textarea x-show="!gov.charterPreview" class="form-textarea" x-model="gov.charter" rows="16" style="font-family:'SF Mono','Fira Code',monospace;font-size:.8125rem" spellcheck="false" placeholder="# Charter…"></textarea>
          <div x-show="gov.charterPreview" class="charter-content" style="border:1px solid var(--border);border-radius:8px;padding:16px;min-height:200px" x-html="renderMarkdown(gov.charter)"></div>
          <div style="display:flex;gap:10px;align-items:center;margin-top:10px">
            <button class="btn btn-primary" @click="saveCharter()" :disabled="gov.savingCharter">
              <span class="btn-spinner" x-show="gov.savingCharter"></span> Save Charter
            </button>
          </div>

          <hr style="border:none;border-top:1px solid var(--border);margin:28px 0">

          <!-- Code of Conduct editor -->
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <h2 style="font-size:1rem;font-weight:600;margin:0">Code of Conduct</h2>
            <div style="display:flex;gap:6px">
              <button class="btn btn-secondary btn-sm" :class="{ active: !gov.cocPreview }" @click="gov.cocPreview = false">Edit</button>
              <button class="btn btn-secondary btn-sm" :class="{ active: gov.cocPreview }" @click="gov.cocPreview = true">Preview</button>
            </div>
          </div>
          <textarea x-show="!gov.cocPreview" class="form-textarea" x-model="gov.coc" rows="16" style="font-family:'SF Mono','Fira Code',monospace;font-size:.8125rem" spellcheck="false" placeholder="# Code of Conduct…"></textarea>
          <div x-show="gov.cocPreview" class="charter-content" style="border:1px solid var(--border);border-radius:8px;padding:16px;min-height:200px" x-html="renderMarkdown(gov.coc)"></div>
          <div style="display:flex;gap:10px;align-items:center;margin-top:10px">
            <button class="btn btn-primary" @click="saveCodeOfConduct()" :disabled="gov.savingCoc">
              <span class="btn-spinner" x-show="gov.savingCoc"></span> Save Code of Conduct
            </button>
          </div>
        </div>
      </div>
    </div><!-- /settings -->

    <!-- ════ SUBMISSIONS ════ -->
    <div x-show="!loading && view === 'submissions' && user?.role === 'admin'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Public Submissions</h1>
          <p class="page-subtitle">Change proposals submitted via the public framework website</p>
        </div>
      </div>

      <div class="tabs">
        <button class="tab-btn" :class="{ active: submissionsFilter === '' }" @click="submissionsFilter = ''; loadSubmissions()">
          All
          <span class="tab-count" x-text="submissionsAll"></span>
        </button>
        <button class="tab-btn" :class="{ active: submissionsFilter === 'new' }" @click="submissionsFilter = 'new'; loadSubmissions()">
          New
          <span class="tab-count" x-text="submissions.filter(s => s.status === 'new').length || submissionsNewCount"></span>
        </button>
        <button class="tab-btn" :class="{ active: submissionsFilter === 'reviewing' }" @click="submissionsFilter = 'reviewing'; loadSubmissions()">
          Reviewing
        </button>
        <button class="tab-btn" :class="{ active: submissionsFilter === 'accepted' }" @click="submissionsFilter = 'accepted'; loadSubmissions()">
          Accepted
        </button>
        <button class="tab-btn" :class="{ active: submissionsFilter === 'rejected' }" @click="submissionsFilter = 'rejected'; loadSubmissions()">
          Rejected
        </button>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Date</th>
                <th>Status</th>
                <th>Type</th>
                <th>Title</th>
                <th>Submitter</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template x-for="sub in filteredSubmissions" :key="sub.id">
                <tr style="cursor:pointer" @click="openSubmissionModal(sub)">
                  <td class="text-xs text-muted" style="white-space:nowrap" x-text="formatDate(sub.created_at)"></td>
                  <td>
                    <span class="badge" :class="{
                      'badge-pending': sub.status === 'new',
                      'badge-edit': sub.status === 'reviewing',
                      'badge-approved': sub.status === 'accepted',
                      'badge-rejected': sub.status === 'rejected',
                      'badge-user': sub.status === 'archived'
                    }" x-text="sub.status"></span>
                  </td>
                  <td class="text-sm" x-text="sub.type"></td>
                  <td class="text-sm fw-500" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="sub.title"></td>
                  <td class="text-sm text-muted" x-text="sub.name"></td>
                  <td>
                    <button class="btn btn-secondary btn-xs" @click.stop="openSubmissionModal(sub)">View</button>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <div class="empty-state" x-show="filteredSubmissions.length === 0" style="padding:48px 20px">
          <svg width="32" height="32" viewBox="0 0 16 16" fill="none"><path d="M14 10V12.67A1.33 1.33 0 0 1 12.67 14H3.33A1.33 1.33 0 0 1 2 12.67V10M8 2v8M5 5l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
          <div class="empty-state-title">No submissions</div>
          <div class="empty-state-sub">Public change proposals will appear here</div>
        </div>
      </div>
    </div><!-- /submissions -->

    <!-- ════ LOGS ════ -->
    <div x-show="!loading && view === 'logs' && user?.role === 'admin'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Activity Logs</h1>
          <p class="page-subtitle">System activity and audit trail</p>
        </div>
        <div class="page-header-actions">
          <button class="btn btn-danger btn-sm" @click="clearLogs()" x-show="logs.length > 0">Clear Logs</button>
        </div>
      </div>

      <!-- Filters -->
      <div class="logs-filters">
        <div class="form-group" style="margin-bottom:0">
          <select class="form-select" style="width:auto;min-width:140px" x-model="logsLevelFilter" @change="loadLogs()">
            <option value="">All levels</option>
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="error">Error</option>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <select class="form-select" style="width:auto;min-width:160px" x-model="logsUserFilter" @change="loadLogs()">
            <option value="">All users</option>
            <template x-for="u in users" :key="u.id">
              <option :value="u.id" x-text="u.name || u.username"></option>
            </template>
          </select>
        </div>
        <div class="form-group" style="margin-bottom:0">
          <input class="form-input" type="text" placeholder="Filter by action..." style="width:180px" x-model="logsActionFilter" @input.debounce.300ms="loadLogs()">
        </div>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Time</th>
                <th>Level</th>
                <th>User</th>
                <th>Action</th>
                <th>IP</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              <template x-for="log in logs" :key="log.id">
                <tr>
                  <td class="text-xs text-muted" style="white-space:nowrap" x-text="formatDate(log.timestamp)"></td>
                  <td>
                    <span class="badge" :class="{
                      'badge-approved': log.level === 'info',
                      'badge-pending': log.level === 'warning',
                      'badge-rejected': log.level === 'error'
                    }" x-text="log.level"></span>
                  </td>
                  <td class="text-sm" x-text="log.user_name || '—'"></td>
                  <td>
                    <span class="log-action" x-text="log.action"></span>
                  </td>
                  <td class="text-xs text-muted" style="font-family:'SF Mono','Fira Code',monospace" x-text="log.ip || '—'"></td>
                  <td class="text-xs text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" x-text="Object.keys(log.details || {}).length ? JSON.stringify(log.details) : '—'"></td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Empty state -->
        <div class="empty-state" x-show="logs.length === 0" style="padding:48px 20px">
          <svg width="32" height="32" viewBox="0 0 16 16" fill="none"><path d="M2 3h12M2 6.5h8M2 10h10M2 13.5h6" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
          <div class="empty-state-title">No activity logs</div>
          <div class="empty-state-sub">Actions will be recorded here as users interact with the system</div>
        </div>

        <!-- Pagination -->
        <div class="logs-pagination" x-show="logsTotal > logsLimit">
          <span class="text-xs text-muted" x-text="`Showing ${logsOffset + 1}–${Math.min(logsOffset + logsLimit, logsTotal)} of ${logsTotal}`"></span>
          <div style="display:flex;gap:6px">
            <button class="btn btn-secondary btn-xs" @click="logsOffset = Math.max(0, logsOffset - logsLimit); loadLogs()" :disabled="logsOffset === 0">Previous</button>
            <button class="btn btn-secondary btn-xs" @click="logsOffset += logsLimit; loadLogs()" :disabled="logsOffset + logsLimit >= logsTotal">Next</button>
          </div>
        </div>
      </div>
    </div><!-- /logs -->

  </main>
</div><!-- /app-layout -->


<!-- ════════════ MODALS ════════════ -->

<!-- Edit / Add / Delete Modal -->
<div class="modal-overlay" x-show="editModal.open" x-cloak @click.self="(!editModal.dirty || confirm('You have unsaved changes. Discard?')) && (editModal.open = false)">
  <div class="modal modal-lg" @click.stop>
    <div class="modal-header">
      <div>
        <div class="modal-title" x-text="
          editModal.mode === 'delete' ? ('Delete ' + frameworkLabel(editModal.type)) :
          editModal.mode === 'add' ? ('Add ' + frameworkLabel(editModal.type)) :
          ('Edit ' + frameworkLabel(editModal.type))
        "></div>
        <div class="modal-subtitle">
          <span x-text="editModal.item ? ((editModal.item.framework_id || '') + (editModal.item.name ? ' — ' + editModal.item.name : '')) : 'New entry'"></span>
        </div>
      </div>
      <button class="modal-close" @click="(!editModal.dirty || confirm('You have unsaved changes. Discard?')) && (editModal.open = false)">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2 2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="modal-body" @input="editModal.dirty = true">

      <!-- DELETE mode -->
      <template x-if="editModal.mode === 'delete'">
        <div>
          <div class="danger-box">
            <div class="danger-box-title">
              <svg width="15" height="15" viewBox="0 0 16 16" fill="none"><path d="M8 1.5L14.5 13.5H1.5L8 1.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 6v3.5M8 11h.01" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
              This will propose a deprecation
            </div>
            <div class="danger-box-body">
              A deprecation proposal for <strong x-text="editModal.item?.name"></strong>
              (<span x-text="editModal.item?.framework_id"></span>) will be submitted for peer review.
              Once approved, the item will be marked <strong>[DEPRECATED]</strong> and preserved in the framework for historical integrity — it will not be removed.
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Replaced by <span class="form-label-hint">(optional)</span></label>
            <select class="form-input" x-model="editModal.form.replaced_by">
              <option value="">— No replacement —</option>
              <template x-if="editModal.type === 'tactic'">
                <template x-for="t in tactics.filter(t => !t.deprecated && t.stix_id !== editModal.item?.stix_id)" :key="t.stix_id">
                  <option :value="t.framework_id" x-text="t.framework_id + ' — ' + t.name"></option>
                </template>
              </template>
              <template x-if="editModal.type === 'technique' || editModal.type === 'subtechnique'">
                <template x-for="t in allTechniques.filter(t => !t.deprecated && t.stix_id !== editModal.item?.stix_id)" :key="t.stix_id">
                  <option :value="t.framework_id" x-text="t.framework_id + ' — ' + t.name"></option>
                </template>
              </template>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Reason for deprecation <span class="form-label-hint">(required)</span></label>
            <textarea class="form-textarea" x-model="editModal.description" placeholder="Explain why this should be deprecated…"></textarea>
          </div>
          <div class="form-error" x-show="editModal.error" x-text="editModal.error"></div>
        </div>
      </template>

      <!-- EDIT / ADD mode -->
      <template x-if="editModal.mode !== 'delete'">
        <div>
          <div class="form-row">
            <template x-if="editModal.mode === 'add'">
              <div class="form-group">
                <label class="form-label"><?= htmlspecialchars($fwName) ?> ID <span class="form-label-hint">auto-generated on approval</span></label>
                <div style="display:flex; align-items:center; gap:8px; height:36px; padding:0 12px; background:var(--bg2); border:1px solid var(--border); border-radius:var(--r2); font-size:.8125rem; color:var(--text2)">
                  <template x-if="editModal.previewId">
                    <span style="font-family:var(--font-mono, monospace); font-weight:600; color:var(--accent)" x-text="editModal.previewId"></span>
                  </template>
                  <template x-if="!editModal.previewId">
                    <span style="opacity:.5">Loading…</span>
                  </template>
                </div>
              </div>
            </template>
            <template x-if="editModal.mode === 'edit'">
              <div class="form-group">
                <label class="form-label"><?= htmlspecialchars($fwName) ?> ID</label>
                <input class="form-input" type="text" x-model="editModal.form.framework_id" readonly>
              </div>
            </template>
            <div class="form-group">
              <label class="form-label">Name</label>
              <input class="form-input" type="text" x-model="editModal.form.name" :placeholder="frameworkLabel(editModal.type) + ' name'">
            </div>
          </div>
          <!-- Shortname — tactic add only -->
          <div class="form-group" x-show="editModal.type === 'tactic' && editModal.mode === 'add'">
            <label class="form-label">
              Shortname
              <span class="form-label-hint" x-text="'slug used to link ' + frameworkLabel('technique').toLowerCase() + 's, e.g. new-' + frameworkLabel('tactic').toLowerCase() + '-name'"></span>
            </label>
            <input class="form-input" type="text" x-model="editModal.form.shortname" :placeholder="'e.g. new-' + frameworkLabel('tactic').toLowerCase() + '-name'">
          </div>
          <div class="form-group">
            <label class="form-label">Description</label>
            <textarea class="form-textarea tall" x-model="editModal.form.description" :placeholder="'Describe this ' + frameworkLabel(editModal.type).toLowerCase() + '…'"></textarea>
          </div>

          <!-- Parent technique (subtechnique add only) -->
          <div class="form-group" x-show="editModal.type === 'subtechnique' && editModal.mode === 'add' && editModal.parentTechnique">
            <label class="form-label" x-text="'Parent ' + frameworkLabel('technique')"></label>
            <div style="display:flex; align-items:center; gap:8px; height:36px; padding:0 12px; background:var(--bg2); border:1px solid var(--border); border-radius:var(--r2); font-size:.8125rem; color:var(--text2)">
              <span style="font-family:var(--font-mono, monospace); font-weight:600; color:var(--accent)" x-text="editModal.parentTechnique?.framework_id"></span>
              <span x-text="editModal.parentTechnique?.name"></span>
            </div>
          </div>
          <!-- Parent technique (subtechnique edit - read-only) -->
          <div class="form-group" x-show="editModal.type === 'subtechnique' && editModal.mode === 'edit' && editModal.item?.parent_technique">
            <label class="form-label" x-text="'Parent ' + frameworkLabel('technique')"></label>
            <div style="display:flex; align-items:center; gap:8px; height:36px; padding:0 12px; background:var(--bg2); border:1px solid var(--border); border-radius:var(--r2); font-size:.8125rem; color:var(--text2)">
              <span style="font-family:var(--font-mono, monospace); font-weight:600; color:var(--accent)" x-text="editModal.item?.parent_technique?.framework_id"></span>
              <span x-text="editModal.item?.parent_technique?.name"></span>
            </div>
          </div>

          <!-- Tactic associations -->
          <div class="form-group" x-show="editModal.type === 'technique'">
            <label class="form-label" x-text="frameworkLabel('tactic') + ' Associations'"></label>
            <div class="checkbox-group">
              <template x-for="tactic in tactics" :key="tactic.stix_id">
                <label
                  class="checkbox-label"
                  :class="{ checked: editModal.form.tactic_shortnames?.includes(tactic.shortname) }"
                  @click.prevent="toggleTacticInForm(tactic.shortname)"
                >
                  <input type="checkbox" :checked="editModal.form.tactic_shortnames?.includes(tactic.shortname)">
                  <span x-text="tactic.framework_id + ' ' + tactic.name"></span>
                </label>
              </template>
            </div>
          </div>

          <!-- Platforms -->
          <div class="form-group" x-show="editModal.type === 'technique' || editModal.type === 'subtechnique'">
            <label class="form-label">Platforms <span class="form-label-hint">(optional)</span></label>
            <div class="checkbox-group">
              <template x-for="platform in ['Windows', 'Linux', 'Mac', 'Social Media', 'Web']" :key="platform">
                <label
                  class="checkbox-label"
                  :class="{ checked: editModal.form.platforms?.includes(platform) }"
                  @click.prevent="togglePlatformInForm(platform)"
                >
                  <input type="checkbox" :checked="editModal.form.platforms?.includes(platform)">
                  <span x-text="platform"></span>
                </label>
              </template>
            </div>
          </div>

          <!-- Related Reports -->
          <div class="form-group" x-show="editModal.type === 'technique' || editModal.type === 'subtechnique'">
            <label class="form-label">Related Reports <span class="form-label-hint">(optional)</span></label>
            <div style="display:flex; flex-direction:column; gap:10px">
              <template x-for="(report, idx) in editModal.form.related_reports" :key="idx">
                <div style="display:flex; gap:8px; align-items:flex-start; padding:12px; background:var(--bg2); border:1px solid var(--border); border-radius:var(--r2)">
                  <div style="flex:1; display:flex; flex-direction:column; gap:6px">
                    <input class="form-input" type="url" x-model="editModal.form.related_reports[idx].url" placeholder="https://example.com/report">
                    <textarea class="form-textarea" style="min-height:52px; font-size:.8125rem" x-model="editModal.form.related_reports[idx].excerpt" placeholder="Relevant excerpt from the report…"></textarea>
                  </div>
                  <button class="btn btn-secondary btn-xs" style="flex-shrink:0; margin-top:4px" @click="editModal.form.related_reports.splice(idx, 1)" title="Remove report">
                    <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                  </button>
                </div>
              </template>
              <button class="btn btn-secondary btn-sm" style="align-self:flex-start" @click="editModal.form.related_reports.push({url:'', excerpt:''})">
                <svg width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                Add report
              </button>
            </div>
          </div>

          <!-- Related Items -->
          <div class="form-group" x-show="editModal.type === 'technique' || editModal.type === 'subtechnique'">
            <label class="form-label">Related Items <span class="form-label-hint">(optional)</span></label>
            <div style="display:flex; flex-direction:column; gap:8px">
              <template x-if="editModal.form.related_techniques?.length > 0">
                <div style="display:flex; flex-direction:column; gap:8px">
                  <template x-for="(rel, idx) in editModal.form.related_techniques" :key="rel.stix_id">
                    <div style="display:flex; flex-direction:column; gap:4px; padding:8px 10px; background:var(--bg2); border:1px solid var(--border); border-radius:var(--r2)">
                      <div style="display:flex; align-items:center; gap:6px">
                        <span x-show="frameworkBadgeLabel(rel.framework_id)" :style="'display:inline-block; font-size:.625rem; font-weight:700; letter-spacing:.03em; padding:1px 5px; border-radius:3px; flex-shrink:0; color:#fff; background:' + frameworkBadgeColor(rel.framework_id)" x-text="frameworkBadgeLabel(rel.framework_id)"></span>
                        <span style="font-family:var(--font-mono, monospace); font-weight:600; color:var(--accent); font-size:.75rem" x-text="rel.framework_id"></span>
                        <span x-text="rel.name" style="font-size:.8125rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1"></span>
                        <button style="background:none; border:none; cursor:pointer; padding:0 2px; color:var(--text3); line-height:1; flex-shrink:0" @click="editModal.form.related_techniques.splice(idx, 1)" title="Remove">
                          <svg width="10" height="10" viewBox="0 0 12 12" fill="none"><path d="M2 2l8 8M10 2l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                        </button>
                      </div>
                      <input class="form-input" type="text" x-model="rel.description" placeholder="Why are these items related?" style="font-size:.75rem; padding:4px 8px">
                    </div>
                  </template>
                </div>
              </template>
              <div style="position:relative" x-data="{ rtSearch: '', rtOpen: false }">
                <input class="form-input" type="text" x-model="rtSearch" @focus="rtOpen = true" @click.away="rtOpen = false" placeholder="Type to search across both frameworks…" style="font-size:.8125rem">
                <div x-show="rtOpen && rtSearch.length >= 1" style="position:absolute; z-index:20; top:100%; left:0; right:0; max-height:200px; overflow-y:auto; background:var(--bg1); border:1px solid var(--border); border-radius:var(--r2); box-shadow:0 8px 24px rgba(0,0,0,.12); margin-top:4px">
                  <template x-for="t in allTechniquesBothFrameworks.filter(t =>
                    !t.deprecated
                    && t.stix_id !== editModal.item?.stix_id
                    && !(editModal.form.related_techniques || []).some(r => r.stix_id === t.stix_id)
                    && (t.framework_id.toLowerCase().includes(rtSearch.toLowerCase()) || t.name.toLowerCase().includes(rtSearch.toLowerCase()))
                  ).slice(0, 12)" :key="t.stix_id">
                    <button style="display:flex; gap:8px; align-items:center; width:100%; padding:7px 12px; background:none; border:none; cursor:pointer; font-size:.8125rem; text-align:left; color:var(--text1)"
                      @mousedown.prevent="editModal.form.related_techniques.push({stix_id: t.stix_id, framework_id: t.framework_id, name: t.name, description: ''}); rtSearch = ''"
                      @mouseover="$el.style.background='var(--bg2)'" @mouseout="$el.style.background='none'"
                    >
                      <span :style="'display:inline-block; font-size:.625rem; font-weight:700; letter-spacing:.03em; padding:1px 5px; border-radius:3px; flex-shrink:0; color:#fff; background:' + frameworkBadgeColor(t.framework_id)" x-text="(profile.subframeworks[t._framework] || {}).id_prefix || ''"></span>
                      <span style="font-family:var(--font-mono, monospace); font-weight:600; color:var(--accent); font-size:.75rem; flex-shrink:0" x-text="t.framework_id"></span>
                      <span x-text="t.name" style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap"></span>
                    </button>
                  </template>
                  <template x-if="allTechniquesBothFrameworks.filter(t =>
                    !t.deprecated
                    && t.stix_id !== editModal.item?.stix_id
                    && !(editModal.form.related_techniques || []).some(r => r.stix_id === t.stix_id)
                    && (t.framework_id.toLowerCase().includes(rtSearch.toLowerCase()) || t.name.toLowerCase().includes(rtSearch.toLowerCase()))
                  ).length === 0">
                    <div style="padding:8px 12px; font-size:.8125rem; color:var(--text3)">No matching items</div>
                  </template>
                </div>
              </div>
            </div>
          </div>

          <div class="form-divider"></div>
          <div class="form-group">
            <label class="form-label">Change description <span class="form-label-hint">(required)</span></label>
            <textarea class="form-textarea" x-model="editModal.description" placeholder="Describe what you changed and why…"></textarea>
          </div>
          <div class="form-error" x-show="editModal.error" x-text="editModal.error"></div>
        </div>
      </template>

    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" @click="(!editModal.dirty || confirm('You have unsaved changes. Discard?')) && (editModal.open = false)">Cancel</button>
      <template x-if="editModal.mode === 'delete'">
        <button class="btn btn-danger" @click="proposeChange()" :disabled="editModal.saving">
          <span class="btn-spinner" x-show="editModal.saving"></span>
          Propose Deprecation
        </button>
      </template>
      <template x-if="editModal.mode !== 'delete'">
        <button class="btn btn-primary" @click="proposeChange()" :disabled="editModal.saving">
          <span class="btn-spinner" x-show="editModal.saving"></span>
          Propose Change
        </button>
      </template>
    </div>
  </div>
</div>

<!-- Review Modal -->
<div class="modal-overlay" x-show="reviewModal.open" x-cloak @click.self="reviewModal.open = false">
  <div class="modal modal-lg" @click.stop>
    <div class="modal-header">
      <div>
        <div class="modal-title">Review Change</div>
        <div class="modal-subtitle" x-show="reviewModal.change">
          <span x-text="reviewModal.change?.framework_id || reviewModal.change?.preview_framework_id || '—'"></span>
          &mdash;
          <span class="badge" :class="'badge-' + reviewModal.change?.type" x-text="reviewModal.change?.type"></span>
          by <span x-text="reviewModal.change?.author_name"></span>
        </div>
      </div>
      <button class="modal-close" @click="reviewModal.open = false">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2 2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="modal-body" x-show="reviewModal.change">

      <!-- Meta -->
      <div class="review-meta-grid">
        <div>
          <div class="review-meta-item-label">Status</div>
          <span class="badge" :class="'badge-' + reviewModal.change?.status" x-text="reviewModal.change?.status"></span>
        </div>
        <div>
          <div class="review-meta-item-label">Submitted</div>
          <div class="text-sm" x-text="formatDate(reviewModal.change?.created_at)"></div>
        </div>
        <div>
          <div class="review-meta-item-label">Target type</div>
          <span class="badge" :class="'badge-' + reviewModal.change?.target_type" x-text="reviewModal.change?.target_type"></span>
        </div>
        <div x-show="reviewModal.change?.reviewer_name">
          <div class="review-meta-item-label">Reviewed by</div>
          <div class="text-sm" x-text="reviewModal.change?.reviewer_name"></div>
        </div>
      </div>

      <!-- Description -->
      <div x-show="reviewModal.change?.description" class="info-box" style="margin-bottom:16px">
        <div style="font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.055em; color:var(--accent); margin-bottom:5px">Change Description</div>
        <div class="text-sm" x-text="reviewModal.change?.description"></div>
      </div>

      <!-- Reviewer comment if reviewed -->
      <div x-show="reviewModal.change?.comment" style="margin-bottom:16px; padding:12px 14px; background:var(--bg2); border-radius:var(--r2); border:1px solid var(--border)">
        <div style="font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.055em; color:var(--text3); margin-bottom:5px">Reviewer Comment</div>
        <div class="text-sm" x-text="reviewModal.change?.comment"></div>
      </div>

      <!-- Diff view -->
      <template x-if="reviewModal.change?.type !== 'delete' && reviewModal.change?.before">
        <div>
          <div class="diff-section-label">What changed</div>
          <template x-for="field in getDiff(reviewModal.change?.before, reviewModal.change?.after)" :key="field.key">
            <div class="diff-field">
              <div class="diff-field-label" x-text="field.key"></div>
              <template x-if="field.key !== 'related_techniques'">
                <div class="diff-row">
                  <div class="diff-before">
                    <span class="diff-label-mini">Before</span>
                    <span x-text="Array.isArray(field.before) ? field.before.join(', ') : (field.before || '—')"></span>
                  </div>
                  <div class="diff-after">
                    <span class="diff-label-mini">After</span>
                    <span x-text="Array.isArray(field.after) ? field.after.join(', ') : (field.after || '—')"></span>
                  </div>
                </div>
              </template>
              <template x-if="field.key === 'related_techniques'">
                <div class="diff-row">
                  <div class="diff-before">
                    <span class="diff-label-mini">Before</span>
                    <template x-if="!field.before?.length"><span>—</span></template>
                    <template x-for="r in (field.before || [])" :key="r.stix_id || r.framework_id">
                      <div style="margin-bottom:6px">
                        <div style="display:flex; align-items:baseline; gap:5px">
                          <span style="font-family:var(--font-mono, monospace); font-weight:700; font-size:.75rem" x-text="r.framework_id"></span>
                          <span style="font-size:.8125rem" x-text="r.name"></span>
                        </div>
                        <div style="font-size:.75rem; opacity:.7; margin-top:2px" x-show="r.description" x-text="r.description"></div>
                      </div>
                    </template>
                  </div>
                  <div class="diff-after">
                    <span class="diff-label-mini">After</span>
                    <template x-if="!field.after?.length"><span>—</span></template>
                    <template x-for="r in (field.after || [])" :key="r.stix_id || r.framework_id">
                      <div style="margin-bottom:6px">
                        <div style="display:flex; align-items:baseline; gap:5px">
                          <span style="font-family:var(--font-mono, monospace); font-weight:700; font-size:.75rem" x-text="r.framework_id"></span>
                          <span style="font-size:.8125rem" x-text="r.name"></span>
                        </div>
                        <div style="font-size:.75rem; opacity:.7; margin-top:2px" x-show="r.description" x-text="r.description"></div>
                      </div>
                    </template>
                  </div>
                </div>
              </template>
            </div>
          </template>
        </div>
      </template>

      <!-- Add proposal details -->
      <template x-if="reviewModal.change?.type === 'add' && reviewModal.change?.after">
        <div>
          <div class="diff-section-label">Proposed item</div>
          <template x-if="reviewModal.change?.target_type === 'subtechnique' && reviewModal.change.after.parent_framework_id">
            <div class="diff-field">
              <div class="diff-field-label" x-text="'Parent ' + frameworkLabel('technique')"></div>
              <div class="diff-after" style="width:100%; font-family:var(--font-mono, monospace); font-weight:600" x-text="reviewModal.change.after.parent_framework_id"></div>
            </div>
          </template>
          <template x-if="reviewModal.change.after.name">
            <div class="diff-field">
              <div class="diff-field-label">Name</div>
              <div class="diff-after" style="width:100%" x-text="reviewModal.change.after.name"></div>
            </div>
          </template>
          <template x-if="reviewModal.change.after.description">
            <div class="diff-field">
              <div class="diff-field-label">Description</div>
              <div class="diff-after" style="width:100%" x-text="reviewModal.change.after.description"></div>
            </div>
          </template>
          <template x-if="(reviewModal.change.after.tactic_shortnames || []).length > 0">
            <div class="diff-field">
              <div class="diff-field-label" x-text="frameworkLabelPlural('tactic')"></div>
              <div class="diff-after" style="width:100%" x-text="reviewModal.change.after.tactic_shortnames.join(', ')"></div>
            </div>
          </template>
          <template x-if="(reviewModal.change.after.platforms || []).length > 0">
            <div class="diff-field">
              <div class="diff-field-label">Platforms</div>
              <div class="diff-after" style="width:100%" x-text="reviewModal.change.after.platforms.join(', ')"></div>
            </div>
          </template>
          <template x-if="(reviewModal.change.after.related_reports || []).length > 0">
            <div class="diff-field">
              <div class="diff-field-label">Related reports</div>
              <div class="diff-after" style="width:100%" x-text="reviewModal.change.after.related_reports.map(r => r.url).join(', ')"></div>
            </div>
          </template>
          <template x-if="(reviewModal.change.after.related_techniques || []).length > 0">
            <div class="diff-field">
              <div class="diff-field-label">Related Items</div>
              <div style="display:flex; flex-direction:column; gap:8px">
                <template x-for="rel in reviewModal.change.after.related_techniques" :key="rel.stix_id || rel.framework_id">
                  <div style="padding:10px 12px; background:rgba(52,199,89,.05); border:1px solid rgba(52,199,89,.14); border-radius:var(--r2)">
                    <div style="display:flex; align-items:baseline; gap:6px; margin-bottom:2px">
                      <span x-show="frameworkBadgeLabel(rel.framework_id)" :style="'display:inline-block; font-size:.625rem; font-weight:700; letter-spacing:.03em; padding:1px 5px; border-radius:3px; color:#fff; background:' + frameworkBadgeColor(rel.framework_id)" x-text="frameworkBadgeLabel(rel.framework_id)"></span>
                      <span style="font-family:var(--font-mono, monospace); font-weight:700; color:var(--accent); font-size:.75rem" x-text="rel.framework_id"></span>
                      <span style="font-size:.8125rem; color:var(--text1)" x-text="rel.name"></span>
                    </div>
                    <div style="font-size:.75rem; color:var(--text3); margin-top:4px" x-show="rel.description">
                      <span x-text="rel.description"></span>
                    </div>
                  </div>
                </template>
              </div>
            </div>
          </template>
        </div>
      </template>

      <!-- Delete summary -->
      <template x-if="reviewModal.change?.type === 'delete'">
        <div class="danger-box" style="margin-bottom:16px">
          <div class="danger-box-title">Proposed deprecation</div>
          <div class="danger-box-body">
            This change proposes marking <strong x-text="reviewModal.change?.framework_id"></strong> as <strong>[DEPRECATED]</strong>.
            <template x-if="reviewModal.change?.after?.replaced_by">
              <span>Replaced by <strong x-text="reviewModal.change.after.replaced_by"></strong>.</span>
            </template>
            The item will be preserved in the framework for historical integrity — it will not be removed from the bundle.
          </div>
        </div>
      </template>

      <!-- Own change warning -->
      <div class="warn-box" x-show="reviewModal.change?.author_id === user?.id && reviewModal.change?.status === 'pending'" style="margin-bottom:14px">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="flex-shrink:0; margin-top:1px"><path d="M8 1.5L14.5 13.5H1.5L8 1.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 6v3.5M8 11h.01" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
        You proposed this change — another team member must approve it.
      </div>

      <!-- Review comment -->
      <div class="form-group mt-16" x-show="reviewModal.change?.status === 'pending' && reviewModal.change?.author_id !== user?.id">
        <label class="form-label">Comment <span class="form-label-hint">(optional)</span></label>
        <textarea class="form-textarea" x-model="reviewModal.comment" placeholder="Add a review comment…"></textarea>
      </div>

    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" @click="reviewModal.open = false">Close</button>
      <template x-if="reviewModal.change?.status === 'pending' && reviewModal.change?.author_id !== user?.id">
        <div style="display:flex; gap:8px">
          <button class="btn btn-danger" @click="rejectChange()" :disabled="reviewModal.saving">
            <span class="btn-spinner" x-show="reviewModal.saving"></span>
            Reject
          </button>
          <button class="btn btn-primary" @click="approveChange()" :disabled="reviewModal.saving" style="background:#1a7f37">
            <span class="btn-spinner" x-show="reviewModal.saving"></span>
            Approve
          </button>
        </div>
      </template>
    </div>
  </div>
</div>

<!-- Release Form Modal -->
<!-- Release Modal (unified: local snapshot + GitHub publish) -->
<div class="modal-overlay" x-show="releaseForm.open" x-cloak @click.self="!releaseForm.saving && (releaseForm.open = false)">
  <div class="modal" @click.stop>
    <div class="modal-header">
      <div>
        <div class="modal-title">Create Release</div>
        <div class="modal-subtitle" x-text="publishStatus.github_configured ? 'Snapshot the framework and publish to GitHub' : 'Snapshot the current working bundle as a versioned release'"></div>
      </div>
      <button class="modal-close" @click="releaseForm.open = false" x-show="!releaseForm.saving">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2 2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <template x-if="!releaseForm.result">
        <div>
          <div class="form-group">
            <label class="form-label">Version bump <span class="form-label-hint">(required)</span></label>
            <div style="display:flex; flex-direction:column; gap:6px" x-data="{ options: [
              { value: 'major', label: 'Major', desc: 'Structural or conceptual overhauls of the framework' },
              { value: 'minor', label: 'Minor', desc: 'Addition of new objects, significant revisions to existing ones' },
              { value: 'patch', label: 'Patch', desc: 'Corrections, clarifications, and minor editorial changes' },
            ]}">
              <template x-for="opt in options" :key="opt.value">
                <label :style="'display:flex; align-items:flex-start; gap:10px; padding:10px 12px; border-radius:var(--r2); border:1px solid ' + (releaseForm.bumpType === opt.value ? 'var(--accent)' : 'var(--border)') + '; background:' + (releaseForm.bumpType === opt.value ? 'rgba(0,122,255,.04)' : 'var(--bg1)') + '; cursor:pointer; transition:all .15s'"
                  @click="releaseForm.bumpType = opt.value; releaseForm.version = computeNextVersion(opt.value); releaseForm.name = opt.value === 'patch' ? latestReleaseName() : ''">
                  <input type="radio" :value="opt.value" x-model="releaseForm.bumpType" style="margin-top:2px; accent-color:var(--accent)" :disabled="releaseForm.saving">
                  <div style="flex:1; min-width:0">
                    <div style="display:flex; align-items:baseline; gap:8px">
                      <span style="font-weight:600; font-size:.875rem" x-text="opt.label"></span>
                      <span style="font-family:var(--font-mono, monospace); font-size:.75rem; color:var(--accent); font-weight:600" x-text="computeNextVersion(opt.value)"></span>
                    </div>
                    <div style="font-size:.75rem; color:var(--text3); margin-top:2px" x-text="opt.desc"></div>
                  </div>
                </label>
              </template>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Version <span class="form-label-hint">(auto-computed)</span></label>
              <input class="form-input" type="text" x-model="releaseForm.version" :disabled="releaseForm.saving" style="font-family:var(--font-mono, monospace); font-weight:600">
            </div>
            <div class="form-group">
              <label class="form-label">Release name</label>
              <input class="form-input" type="text" x-model="releaseForm.name" placeholder="e.g. Spring 2025" :disabled="releaseForm.saving">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Release notes</label>
            <textarea class="form-textarea" x-model="releaseForm.notes" placeholder="Summarise what changed in this release…" :disabled="releaseForm.saving"></textarea>
          </div>
          <div class="info-box mt-8">
            The full framework bundle (all sub-frameworks) will be snapshotted.
          </div>
          <div class="info-box mt-8" x-show="publishStatus.github_configured && publishStatus.unpublished_count > 0">
            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" style="vertical-align:middle;margin-right:4px"><path d="M8 1v9M4 6l4-5 4 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M2 11v3h12v-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <strong x-text="publishStatus.unpublished_count"></strong> approved
            <span x-text="publishStatus.unpublished_count === 1 ? 'change' : 'changes'"></span> will be published to GitHub.
          </div>
          <div class="warn-box mt-8" x-show="!publishStatus.github_configured" style="display:flex;align-items:center;gap:8px">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="flex-shrink:0"><path d="M8 1.5L14.5 13.5H1.5L8 1.5z" stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 6v3.5M8 11h.01" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
            GitHub not configured — release will be saved locally only. Configure in Settings to also publish.
          </div>
          <div x-show="releaseForm.saving" class="mt-8" x-cloak>
            <template x-for="s in [
              { id: 'yaml', label: 'Generating YAML files' },
              { id: 'changelog', label: 'Building changelog' },
              { id: 'documentation', label: 'Generating documentation' },
              { id: 'translations', label: 'Translating content' },
              { id: 'github_auth', label: 'Authenticating with GitHub' },
              { id: 'push', label: 'Pushing to GitHub' },
              { id: 'finalize', label: 'Finalizing release' },
            ].filter(s => releaseForm.steps[s.id])" :key="s.id">
              <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:.8125rem">
                <template x-if="releaseForm.steps[s.id] === 'done'">
                  <svg width="14" height="14" viewBox="0 0 16 16" fill="none" style="flex-shrink:0"><circle cx="8" cy="8" r="6.5" stroke="var(--check)" stroke-width="1.3"/><path d="M5 8l2 2 4-4" stroke="var(--check)" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </template>
                <template x-if="releaseForm.steps[s.id] === 'active'">
                  <span class="btn-spinner" style="flex-shrink:0;width:14px;height:14px"></span>
                </template>
                <span x-text="s.label" :style="releaseForm.steps[s.id] === 'done' ? 'color:var(--check)' : 'color:var(--text)'"></span>
              </div>
            </template>
          </div>
        </div>
      </template>
      <template x-if="releaseForm.result">
        <div>
          <div class="info-box" style="border-color:var(--check);background:rgba(52,199,89,.06)">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style="vertical-align:middle;margin-right:6px"><circle cx="8" cy="8" r="6.5" stroke="var(--check)" stroke-width="1.3"/><path d="M5 8l2 2 4-4" stroke="var(--check)" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span x-text="releaseForm.result.pr_url ? 'Release created and pull request opened.' : 'Release created successfully.'"></span>
          </div>
          <template x-if="releaseForm.result.pr_url">
            <div style="margin-top:12px">
              <a :href="releaseForm.result.pr_url" target="_blank" rel="noopener" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><path d="M6 3H3v10h10v-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 1h6v6M15 1L7 9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Open Pull Request #<span x-text="releaseForm.result.pr_number"></span>
              </a>
            </div>
          </template>
        </div>
      </template>
    </div>
    <div class="modal-footer" x-show="!releaseForm.result">
      <button class="btn btn-secondary" @click="releaseForm.open = false" :disabled="releaseForm.saving">Cancel</button>
      <button class="btn btn-primary" @click="createRelease()" :disabled="releaseForm.saving || !releaseForm.version.trim()">
        <span class="btn-spinner" x-show="releaseForm.saving"></span>
        <span x-text="publishStatus.github_configured ? 'Create & Publish' : 'Create Release'"></span>
      </button>
    </div>
    <div class="modal-footer" x-show="releaseForm.result">
      <button class="btn btn-secondary" @click="releaseForm.open = false">Close</button>
    </div>
  </div>
</div>

<!-- User Modal -->
<div class="modal-overlay" x-show="userModal.open" x-cloak @click.self="userModal.open = false">
  <div class="modal" @click.stop>
    <div class="modal-header">
      <div>
        <div class="modal-title" x-text="userModal.mode === 'add' ? 'Add User' : 'Edit User'"></div>
        <div class="modal-subtitle" x-show="userModal.mode === 'edit'" x-text="userModal.form?.username ? '@' + userModal.form.username : ''"></div>
      </div>
      <button class="modal-close" @click="userModal.open = false">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2 2l10 10M12 2 2 12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">
            Username
            <span class="form-label-hint" x-show="userModal.mode === 'add'">(required)</span>
          </label>
          <input class="form-input" type="text" x-model="userModal.form.username"
            :readonly="userModal.mode === 'edit'"
            placeholder="e.g. jsmith"
          >
        </div>
        <div class="form-group">
          <label class="form-label">Full name</label>
          <input class="form-input" type="text" x-model="userModal.form.name" placeholder="Jane Smith">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Email</label>
          <input class="form-input" type="email" x-model="userModal.form.email" placeholder="jane@example.com">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select class="form-select" x-model="userModal.form.role">
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">TSC Role</label>
        <select class="form-select" x-model="userModal.form.tsc_role">
          <option value="">None</option>
          <option value="member">Member</option>
          <option value="vice-president">Vice-President</option>
          <option value="president">President</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">
          Password
          <span class="form-label-hint" x-text="userModal.mode === 'edit' ? '(leave blank to keep current)' : '(required)'"></span>
        </label>
        <input class="form-input" type="password" x-model="userModal.form.password" placeholder="Enter password">
      </div>
      <div class="form-error" x-show="userModal.error" x-text="userModal.error"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" @click="userModal.open = false">Cancel</button>
      <button class="btn btn-primary" @click="saveUser()" :disabled="userModal.saving">
        <span class="btn-spinner" x-show="userModal.saving"></span>
        <span x-text="userModal.mode === 'add' ? 'Create User' : 'Save Changes'"></span>
      </button>
    </div>
  </div>
</div>

<!-- Submission Modal -->
<div class="modal-overlay" x-show="submissionModal.open" x-cloak @click.self="submissionModal.open = false">
  <div class="modal modal-lg" @click.stop>
    <div class="modal-header">
      <div>
        <div class="modal-title" x-text="submissionModal.sub?.title || 'Submission'"></div>
        <div class="modal-subtitle" x-text="'Submitted by ' + (submissionModal.sub?.name || '—') + (submissionModal.sub?.organisation ? ' (' + submissionModal.sub.organisation + ')' : '')"></div>
      </div>
      <button class="modal-close" @click="submissionModal.open = false">
        <svg width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
      </button>
    </div>
    <div class="modal-body">
      <div class="review-meta-grid">
        <div>
          <div class="review-meta-item-label">Status</div>
          <span class="badge" :class="{
            'badge-pending': submissionModal.sub?.status === 'new',
            'badge-edit': submissionModal.sub?.status === 'reviewing',
            'badge-approved': submissionModal.sub?.status === 'accepted',
            'badge-rejected': submissionModal.sub?.status === 'rejected',
            'badge-user': submissionModal.sub?.status === 'archived'
          }" x-text="submissionModal.sub?.status"></span>
        </div>
        <div>
          <div class="review-meta-item-label">Change Type</div>
          <div class="text-sm" x-text="submissionModal.sub?.type"></div>
        </div>
        <div>
          <div class="review-meta-item-label">Email</div>
          <div class="text-sm"><a :href="'mailto:' + submissionModal.sub?.email" x-text="submissionModal.sub?.email" style="color:var(--accent)"></a></div>
        </div>
        <div>
          <div class="review-meta-item-label">Submitted</div>
          <div class="text-sm" x-text="formatDate(submissionModal.sub?.created_at)"></div>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Description &amp; Rationale</label>
        <div style="padding:12px 14px;background:var(--bg2);border-radius:var(--r2);border:1px solid var(--border);font-size:.875rem;line-height:1.6;white-space:pre-wrap" x-text="submissionModal.sub?.description"></div>
      </div>

      <template x-if="submissionModal.sub?.references">
        <div class="form-group">
          <label class="form-label">Related Framework Objects</label>
          <div class="text-sm" style="padding:10px 14px;background:var(--bg2);border-radius:var(--r2);border:1px solid var(--border);font-family:'SF Mono','Fira Code',monospace;font-size:.8125rem" x-text="submissionModal.sub?.references"></div>
        </div>
      </template>

      <div class="form-divider"></div>

      <div class="form-group">
        <label class="form-label">Admin Notes</label>
        <textarea class="form-textarea" x-model="submissionModal.notes" placeholder="Internal notes about this submission..."></textarea>
      </div>

      <div class="form-group">
        <label class="form-label">Set Status</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button class="btn btn-sm" :class="submissionModal.newStatus === 'new' ? 'btn-primary' : 'btn-secondary'" @click="submissionModal.newStatus = 'new'">New</button>
          <button class="btn btn-sm" :class="submissionModal.newStatus === 'reviewing' ? 'btn-primary' : 'btn-secondary'" @click="submissionModal.newStatus = 'reviewing'">Reviewing</button>
          <button class="btn btn-sm" :class="submissionModal.newStatus === 'accepted' ? 'btn-primary' : 'btn-secondary'" @click="submissionModal.newStatus = 'accepted'">Accepted</button>
          <button class="btn btn-sm" :class="submissionModal.newStatus === 'rejected' ? 'btn-primary' : 'btn-secondary'" @click="submissionModal.newStatus = 'rejected'">Rejected</button>
          <button class="btn btn-sm" :class="submissionModal.newStatus === 'archived' ? 'btn-primary' : 'btn-secondary'" @click="submissionModal.newStatus = 'archived'">Archived</button>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger btn-sm" @click="deleteSubmission()" style="margin-right:auto">Delete</button>
      <button class="btn btn-secondary" @click="submissionModal.open = false">Cancel</button>
      <button class="btn btn-primary" @click="saveSubmission()" :disabled="submissionModal.saving">
        <span class="btn-spinner" x-show="submissionModal.saving"></span>
        Save
      </button>
    </div>
  </div>
</div>

<!-- Toast Container -->
<div class="toast-container">
  <template x-for="toast in toasts" :key="toast.id">
    <div class="toast" :class="'toast-' + toast.type" :id="'toast-' + toast.id">
      <svg x-show="toast.type === 'success'" width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <svg x-show="toast.type === 'error'" width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 5v3.5M8 10.5h.01" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      <svg x-show="toast.type === 'info'" width="16" height="16" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.3"/><path d="M8 7.5V11M8 5.5h.01" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>
      <span x-text="toast.message"></span>
    </div>
  </template>
</div>

<script>
  window.__PROFILE__ = <?= json_encode($jsProfile, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  document.addEventListener('alpine:init', () => {
    Alpine.data('frameworkApp', () => ({
      profile: window.__PROFILE__ || { product_name: 'Framework Manager', framework_name: 'Framework', subframeworks: {} },
      user: null,
      view: 'dashboard',
      loading: true,
      loadingTechniques: false,
      toasts: [],

      // Dashboard stats (both frameworks)
      dashboardStats: {}, // keyed by sub-framework slug: { tactic, technique, subtechnique }

      // Framework — default set to the first configured sub-framework in init()
      activeFramework: '', // set to the first configured sub-framework in init()
      tactics: [],
      techniques: [],
      allTechniques: [],
      allTechniquesBothFrameworks: [],
      selectedTacticId: null,
      selectedTactic: null,
      expandedTechniques: {},

      // Edit Modal
      editModal: {
        open: false,
        mode: 'view',
        type: 'technique',
        item: null,
        form: {},
        description: '',
        saving: false,
        error: null,
        dirty: false,
      },

      // Changes
      changes: [],
      changesFilter: 'pending',

      // Review modal
      reviewModal: {
        open: false,
        change: null,
        comment: '',
        saving: false,
      },

      // Releases
      releases: [],
      releaseForm: { open: false, version: '', bumpType: 'patch', name: '', notes: '', saving: false, steps: {}, result: null },

      // Users
      users: [],
      userModal: { open: false, mode: 'add', form: {}, saving: false, error: null },

      // GitHub / Publish
      publishForm: { open: false }, // deprecated — kept for compatibility
      publishStatus: { unpublished_count: 0, last_published_at: null, github_configured: false },
      githubSettings: { open: false, app_id: '', installation_id: '', owner: '', repo: '', private_key: '', key_exists: false, saving: false, testing: false, test_result: null, deepl_api_key: '', deepl_key_exists: false, translation_languages: '', deepl_testing: false, deepl_test_result: null, public_api_token: '' },
      settingsTab: 'profile',
      profileAvailable: [], profileImport: '', profileError: '', profileShowAdvanced: false,
      instanceSourceUrl: '', upstreamSourceUrl: '', instanceSourceSaving: false, instanceSourceMsg: '',
      profileForm: {
        product_name: '', framework_name: '', org_name: '', org_short: '', org_description: '',
        source_name: '', artifact_slug: '', base_url: '', stix_property_prefix: '',
        extension_definition_id: '', extension_definition_name: '', identity_ref: '',
        subs: [],
      },
      // Fixed STIX level roles (only the display names are brandable).
      levelKeys: ['tactic', 'technique', 'subtechnique'],
      levelCanonical: { tactic: 'Tactic', technique: 'Technique', subtechnique: 'Sub-technique' },

      // Committee
      charterHtml: '',
      codeOfConductHtml: '',
      gov: { charter: '', coc: '', charterPreview: false, cocPreview: false, savingCharter: false, savingCoc: false, loaded: false },
      committeeMembers: [],
      committeeTab: 'members',

      // Logs
      logs: [],
      logsTotal: 0,
      logsOffset: 0,
      logsLimit: 50,
      logsLevelFilter: '',
      logsUserFilter: '',
      logsActionFilter: '',

      // Submissions
      submissions: [],
      submissionsFilter: '',
      submissionsAll: 0,
      submissionsNewCount: 0,
      submissionModal: { open: false, sub: null, notes: '', newStatus: '', saving: false },

      // ── Getters ──────────────────────────────────────────────────────────────

      get pendingCount() {
        return this.changes.filter(c => c.status === 'pending').length;
      },

      get filteredChanges() {
        if (this.changesFilter === 'all') return this.changes;
        return this.changes.filter(c => c.status === this.changesFilter);
      },

      get groupedTechniques() {
        const parents = this.techniques.filter(t => !t.is_subtechnique);
        const subs = this.techniques.filter(t => t.is_subtechnique);
        const childMap = {};
        for (const s of subs) {
          const pid = s.parent_technique?.framework_id;
          if (pid) {
            if (!childMap[pid]) childMap[pid] = [];
            childMap[pid].push(s);
          }
        }
        return parents.map(t => ({
          parent: t,
          children: childMap[t.framework_id] || [],
        }));
      },

      get filteredSubmissions() {
        if (!this.submissionsFilter) return this.submissions;
        return this.submissions.filter(s => s.status === this.submissionsFilter);
      },

      // ── Framework Labels ──────────────────────────────────────────────────────

      frameworkLabel(key) {
        const sf = this.profile.subframeworks[this.activeFramework] || {};
        if (key === 'framework') return sf.label || this.activeFramework;
        const lvl = (sf.levels || {})[key];
        return (lvl && lvl.label) || key;
      },
      frameworkLabelPlural(key) {
        const sf = this.profile.subframeworks[this.activeFramework] || {};
        if (key === 'framework') return sf.label || this.activeFramework;
        const lvl = (sf.levels || {})[key];
        return (lvl && lvl.plural) || ((lvl && lvl.label) ? lvl.label + 's' : key + 's');
      },
      // Return the id_prefix of whichever sub-framework an ID belongs to.
      frameworkBadgeLabel(frameworkId) {
        if (!frameworkId) return '';
        for (const slug in this.profile.subframeworks) {
          const pfx = this.profile.subframeworks[slug].id_prefix;
          if (pfx && frameworkId.startsWith(pfx)) return pfx;
        }
        return '';
      },
      // Distinct badge colour per sub-framework (by declaration order).
      frameworkBadgeColor(frameworkId) {
        const palette = ['#6366f1', '#0ea5e9', '#10b981', '#f59e0b'];
        const slugs = Object.keys(this.profile.subframeworks);
        const label = this.frameworkBadgeLabel(frameworkId);
        for (let i = 0; i < slugs.length; i++) {
          if (this.profile.subframeworks[slugs[i]].id_prefix === label) return palette[i % palette.length];
        }
        return palette[0];
      },
      // The active sub-framework's id_prefix (used to hide the badge for the current framework).
      activeBadgeLabel() {
        return (this.profile.subframeworks[this.activeFramework] || {}).id_prefix || '';
      },

      async switchFramework(fw) {
        if (this.activeFramework === fw) return;
        this.activeFramework = fw;
        this.selectedTacticId = null;
        this.selectedTactic = null;
        this.techniques = [];
        await Promise.all([this.loadTactics(), this.loadAllTechniques()]);
      },

      // ── Init ─────────────────────────────────────────────────────────────────

      async init() {
        this.loading = true;
        try {
          const userData = await this.api('get_current_user');
          if (!userData.user) {
            window.location.href = 'index.php';
            return;
          }
          this.user = userData.user;
          // Default to the first configured sub-framework.
          const slugs = this.frameworkSlugs();
          if (slugs.length && !slugs.includes(this.activeFramework)) this.activeFramework = slugs[0];
          await Promise.all([
            this.loadTactics(),
            this.loadChanges(),
            this.loadReleases(),
          ]);
          await this.loadAllTechniques();
          // Load dashboard stats for both frameworks
          await this.loadDashboardStats();
        } catch (e) {
          console.error(e);
        } finally {
          this.loading = false;
        }
      },

      // ── API helper ───────────────────────────────────────────────────────────

      async api(action, method = 'GET', data = null) {
        const opts = {
          method,
          headers: { 'Content-Type': 'application/json' },
        };
        if (data && method !== 'GET') {
          opts.body = JSON.stringify(data);
        }
        const res = await fetch(`api.php?action=${action}`, opts);
        if (res.status === 401) {
          window.location.href = 'index.php';
          return {};
        }
        return res.json();
      },

      // ── Data Loading ─────────────────────────────────────────────────────────

      async loadTactics() {
        const data = await this.api('get_tactics&framework=' + this.activeFramework);
        this.tactics = data.tactics || [];
      },

      async loadAllTechniques() {
        const data = await this.api('get_techniques&framework=' + this.activeFramework);
        this.allTechniques = data.techniques || [];
      },

      frameworkSlugs() { return Object.keys(this.profile.subframeworks || {}); },

      // Distinct colours per level for the dashboard icons.
      levelColor(lvl) { return ({ tactic: '#5856D6', technique: '#1a7f37', subtechnique: '#c27200' })[lvl] || 'var(--accent)'; },
      levelTint(lvl)  { return ({ tactic: 'rgba(88,86,214,.08)', technique: 'rgba(52,199,89,.08)', subtechnique: 'rgba(255,149,0,.08)' })[lvl] || 'var(--accent-soft)'; },

      async loadAllTechniquesBoth() {
        const slugs = this.frameworkSlugs();
        const results = await Promise.all(slugs.map(s => this.api('get_techniques&framework=' + s)));
        this.allTechniquesBothFrameworks = results.flatMap((r, i) =>
          (r.techniques || []).map(t => ({ ...t, _framework: slugs[i] })));
      },

      async loadDashboardStats() {
        const slugs = this.frameworkSlugs();
        const results = await Promise.all(slugs.map(s => Promise.all([
          this.api('get_tactics&framework=' + s),
          this.api('get_techniques&framework=' + s),
        ])));
        const stats = {};
        slugs.forEach((s, i) => {
          const [tac, tech] = results[i];
          const all = tech.techniques || [];
          stats[s] = {
            tactic: (tac.tactics || []).length,
            technique: all.filter(t => !t.is_subtechnique).length,
            subtechnique: all.filter(t => t.is_subtechnique).length,
          };
        });
        this.dashboardStats = stats;
      },

      async loadChanges() {
        const data = await this.api('get_changes');
        this.changes = data.changes || [];
      },

      async loadReleases() {
        const data = await this.api('get_releases');
        this.releases = data.releases || [];
      },

      async loadUsers() {
        const data = await this.api('get_users');
        this.users = data.users || [];
      },

      async loadLogs() {
        try {
          let url = `get_logs&limit=${this.logsLimit}&offset=${this.logsOffset}`;
          if (this.logsLevelFilter) url += `&level=${this.logsLevelFilter}`;
          if (this.logsUserFilter) url += `&user_id=${this.logsUserFilter}`;
          if (this.logsActionFilter) url += `&action_filter=${encodeURIComponent(this.logsActionFilter)}`;
          const data = await this.api(url);
          this.logs = data.logs || [];
          this.logsTotal = data.total || 0;
        } catch (e) { /* ignore — admin-only */ }
      },

      async clearLogs() {
        if (!confirm('Clear all activity logs? This cannot be undone.')) return;
        try {
          await this.api('clear_logs', 'POST');
          this.showToast('Logs cleared', 'success');
          await this.loadLogs();
        } catch (e) {
          this.showToast('Failed to clear logs', 'error');
        }
      },

      async loadSubmissions() {
        try {
          let url = 'get_submissions';
          if (this.submissionsFilter) url += `&status=${this.submissionsFilter}`;
          const data = await this.api(url);
          this.submissions = data.submissions || [];
          if (!this.submissionsFilter) {
            this.submissionsAll = this.submissions.length;
            this.submissionsNewCount = this.submissions.filter(s => s.status === 'new').length;
          }
        } catch (e) { /* admin-only */ }
      },

      openSubmissionModal(sub) {
        this.submissionModal = {
          open: true,
          sub,
          notes: sub.notes || '',
          newStatus: sub.status,
          saving: false,
        };
      },

      async saveSubmission() {
        this.submissionModal.saving = true;
        try {
          const res = await this.api('update_submission', 'POST', {
            id: this.submissionModal.sub.id,
            status: this.submissionModal.newStatus,
            notes: this.submissionModal.notes,
          });
          if (res.success) {
            this.submissionModal.open = false;
            this.showToast('Submission updated', 'success');
            await this.loadSubmissions();
          } else {
            this.showToast(res.error || 'Failed to update', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        } finally {
          this.submissionModal.saving = false;
        }
      },

      async deleteSubmission() {
        if (!confirm('Delete this submission permanently?')) return;
        try {
          const res = await this.api('delete_submission', 'POST', { id: this.submissionModal.sub.id });
          if (res.success) {
            this.submissionModal.open = false;
            this.showToast('Submission deleted', 'success');
            await this.loadSubmissions();
          } else {
            this.showToast(res.error || 'Failed to delete', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        }
      },

      // ── View Navigation ──────────────────────────────────────────────────────

      async setView(v) {
        this.view = v;
        if (v === 'changes') await this.loadChanges();
        if (v === 'releases') { await this.loadReleases(); this.loadPublishStatus(); }
        if (v === 'users') await this.loadUsers();
        if (v === 'settings') { await this.loadGithubConfig(); this.loadPublishStatus(); this.loadProfileConfig(); }
        if (v === 'logs') { this.logsOffset = 0; await this.loadLogs(); if (!this.users.length) await this.loadUsers(); }
        if (v === 'submissions') { this.submissionsFilter = ''; await this.loadSubmissions(); }
        if (v === 'committee') { if (!this.charterHtml) await this.loadCharter(); if (!this.committeeMembers.length) await this.loadCommitteeMembers(); }
        if (v === 'framework' && this.tactics.length === 0) await this.loadTactics();
      },

      // ── Framework ────────────────────────────────────────────────────────────

      async selectTactic(tactic) {
        this.selectedTacticId = tactic.stix_id;
        this.selectedTactic = tactic;
        this.loadingTechniques = true;
        try {
          const data = await this.api(`get_techniques&framework=${this.activeFramework}&tactic=${encodeURIComponent(tactic.shortname)}`);
          this.techniques = data.techniques || [];
        } finally {
          this.loadingTechniques = false;
        }
      },

      getTechniqueCountForTactic(shortname) {
        return this.allTechniques.filter(t => !t.is_subtechnique && t.tactic_shortnames?.includes(shortname)).length;
      },
      getSubTechniqueCountForTactic(shortname) {
        return this.allTechniques.filter(t => t.is_subtechnique && t.tactic_shortnames?.includes(shortname)).length;
      },

      // ── Edit Modal ───────────────────────────────────────────────────────────

      async openEditModal(item, type = 'technique') {
        if (type === 'technique' || type === 'subtechnique') {
          await Promise.all([this.loadAllTechniques(), this.loadAllTechniquesBoth()]);
        }
        this.editModal = {
          open: true,
          mode: 'edit',
          type,
          item,
          form: {
            framework_id: item.framework_id || '',
            name: item.name || '',
            description: item.description || '',
            tactic_shortnames: [...(item.tactic_shortnames || [])],
            platforms: [...(item.platforms || [])],
            related_reports: (item.related_reports || []).map(r => ({...r})),
            related_techniques: (item.related_techniques || []).map(r => ({...r})),
          },
          description: '',
          saving: false,
          error: null,
          dirty: false,
        };
      },

      async openAddModal(type = 'technique', parentTechnique = null) {
        if (type === 'technique' || type === 'subtechnique') {
          await Promise.all([this.loadAllTechniques(), this.loadAllTechniquesBoth()]);
        }
        const defaultTactic = (type === 'technique' && this.selectedTactic) ? [this.selectedTactic.shortname] : [];
        const nonce = Date.now();
        this.editModal = {
          open: true,
          mode: 'add',
          type,
          item: null,
          parentTechnique: parentTechnique || null,
          previewId: null,
          previewIdNonce: nonce,
          form: {
            framework_id: '',
            name: '',
            description: '',
            shortname: '',
            tactic_shortnames: defaultTactic,
            platforms: [],
            related_reports: [],
            related_techniques: [],
          },
          description: '',
          saving: false,
          error: null,
          dirty: false,
        };
        try {
          let idType = type;
          let idUrl = `get_next_id&id_type=${idType}&framework=${this.activeFramework}`;
          if (type === 'technique' && this.selectedTactic) {
            idUrl += `&parent_id=${encodeURIComponent(this.selectedTactic.framework_id)}`;
          } else if (type === 'subtechnique' && parentTechnique) {
            idUrl += `&parent_id=${encodeURIComponent(parentTechnique.framework_id)}`;
          }
          const res = await this.api(idUrl, 'GET');
          if (res.id && this.editModal.previewIdNonce === nonce) this.editModal.previewId = res.id;
        } catch (e) {}
      },

      async openDeleteModal(item, type = 'technique') {
        if (type === 'technique' || type === 'subtechnique') await this.loadAllTechniques();
        this.editModal = {
          open: true,
          mode: 'delete',
          type,
          item,
          form: { replaced_by: '' },
          description: '',
          saving: false,
          error: null,
          dirty: false,
        };
      },

      pendingChangeFor(stixId) {
        return this.changes.find(c => c.status === 'pending' && c.stix_id === stixId);
      },

      pendingAddTactics() {
        return this.changes.filter(c => c.status === 'pending' && c.type === 'add' && c.target_type === 'tactic' && c.framework === this.activeFramework);
      },

      pendingAddTechniques(tacticShortname) {
        return this.changes.filter(c =>
          c.status === 'pending' && c.type === 'add' && c.target_type === 'technique'
          && c.framework === this.activeFramework
          && (c.after?.tactic_shortnames || []).includes(tacticShortname)
        );
      },

      pendingAddSubTechniques(parentId) {
        return this.changes.filter(c =>
          c.status === 'pending' && c.type === 'add' && c.target_type === 'subtechnique'
          && c.framework === this.activeFramework
          && (c.after?.parent_framework_id || '') === parentId
        );
      },

      toggleTacticInForm(shortname) {
        const arr = this.editModal.form.tactic_shortnames;
        const idx = arr.indexOf(shortname);
        if (idx >= 0) arr.splice(idx, 1);
        else arr.push(shortname);
      },

      togglePlatformInForm(platform) {
        const arr = this.editModal.form.platforms;
        const idx = arr.indexOf(platform);
        if (idx >= 0) arr.splice(idx, 1);
        else arr.push(platform);
      },

      async proposeChange() {
        this.editModal.error = null;
        if (!this.editModal.description.trim()) {
          this.editModal.error = 'Please provide a change description.';
          return;
        }

        const mode = this.editModal.mode;
        const type = this.editModal.type;

        let payload = {
          type: mode,
          target_type: type,
          framework: this.activeFramework,
          stix_id: this.editModal.item?.stix_id || '',
          framework_id: mode === 'add' ? '' : (this.editModal.item?.framework_id || ''),
          description: this.editModal.description,
        };

        if (mode === 'edit') {
          if (!this.editModal.form.name.trim()) {
            this.editModal.error = 'Name is required.';
            return;
          }
          payload.before = {
            name: this.editModal.item.name,
            description: this.editModal.item.description,
            tactic_shortnames: this.editModal.item.tactic_shortnames,
            platforms: this.editModal.item.platforms,
            related_reports: this.editModal.item.related_reports || [],
            related_techniques: this.editModal.item.related_techniques || [],
          };
          payload.after = {
            name: this.editModal.form.name,
            description: this.editModal.form.description,
            tactic_shortnames: this.editModal.form.tactic_shortnames,
            platforms: this.editModal.form.platforms,
            related_reports: (this.editModal.form.related_reports || []).filter(r => r.url?.trim()),
            related_techniques: this.editModal.form.related_techniques || [],
          };
        } else if (mode === 'add') {
          if (!this.editModal.form.name.trim()) {
            this.editModal.error = 'Name is required.';
            return;
          }
          if (this.editModal.type === 'tactic' && !this.editModal.form.shortname.trim()) {
            this.editModal.error = 'Shortname is required for a new ' + this.frameworkLabel('tactic').toLowerCase() + '.';
            return;
          }
          if (this.editModal.type === 'tactic' && !/^[a-z][a-z0-9,.\-]*$/.test(this.editModal.form.shortname.trim())) {
            this.editModal.error = 'Shortname must be a lowercase slug (letters, digits, hyphens only, e.g. "observed-asset").';
            return;
          }
          payload.after = {
            name: this.editModal.form.name,
            description: this.editModal.form.description,
            shortname: this.editModal.form.shortname,
            tactic_shortnames: this.editModal.form.tactic_shortnames,
            platforms: this.editModal.form.platforms,
            related_reports: (this.editModal.form.related_reports || []).filter(r => r.url?.trim()),
            related_techniques: this.editModal.form.related_techniques || [],
            parent_framework_id: type === 'subtechnique' ? (this.editModal.parentTechnique?.framework_id || '') : type === 'technique' ? (this.selectedTactic?.framework_id || '') : '',
          };
        }
        if (mode === 'delete') {
          const replacedBy = this.editModal.form.replaced_by || null;
          if (replacedBy) {
            payload.after = { replaced_by: replacedBy };
          }
        }

        this.editModal.saving = true;
        try {
          const res = await this.api('propose_change', 'POST', payload);
          if (res.success) {
            await this.loadChanges();
            this.editModal.open = false;
            this.showToast('Change proposal submitted — awaiting peer review', 'success');
          } else {
            this.editModal.error = res.error || 'Failed to submit change.';
          }
        } catch (e) {
          this.editModal.error = 'Network error. Please try again.';
        } finally {
          this.editModal.saving = false;
        }
      },

      async withdrawChange(change) {
        if (!confirm('Withdraw this proposal? It will be permanently deleted.')) return;
        try {
          const res = await this.api('delete_change', 'POST', { change_id: change.id });
          if (res.success) {
            this.showToast('Proposal withdrawn', 'success');
            await this.loadChanges();
          } else {
            this.showToast(res.error || 'Failed to withdraw', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        }
      },

      // ── Review Modal ─────────────────────────────────────────────────────────

      openReviewModal(change) {
        this.reviewModal = {
          open: true,
          change,
          comment: '',
          saving: false,
        };
      },

      async approveChange() {
        this.reviewModal.saving = true;
        try {
          const res = await this.api('approve_change', 'POST', {
            change_id: this.reviewModal.change.id,
            comment: this.reviewModal.comment,
          });
          if (res.success) {
            this.reviewModal.open = false;
            this.showToast('Change approved and applied to framework', 'success');
            await this.loadChanges();
            // Reload framework data to reflect the applied change
            await this.loadTactics();
            await this.loadAllTechniques();
            if (this.view === 'framework' && this.selectedTactic) {
              // Re-select to get fresh techniques from server
              await this.selectTactic(this.selectedTactic);
            }
          } else {
            this.showToast(res.error || 'Failed to approve change', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        } finally {
          this.reviewModal.saving = false;
        }
      },

      async rejectChange() {
        this.reviewModal.saving = true;
        try {
          const res = await this.api('reject_change', 'POST', {
            change_id: this.reviewModal.change.id,
            comment: this.reviewModal.comment,
          });
          if (res.success) {
            this.reviewModal.open = false;
            this.showToast('Change rejected', 'success');
            await this.loadChanges();
          } else {
            this.showToast(res.error || 'Failed to reject change', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        } finally {
          this.reviewModal.saving = false;
        }
      },

      // ── Releases ─────────────────────────────────────────────────────────────

      computeNextVersion(bumpType) {
        const sorted = this.releases
          .filter(r => /^\d+\.\d+/.test(r.version))
          .sort((a, b) => {
            const pa = a.version.split('.').map(Number), pb = b.version.split('.').map(Number);
            return (pa[0] - pb[0]) || (pa[1] - pb[1]) || ((pa[2] || 0) - (pb[2] || 0));
          });
        let major = 0, minor = 0, patch = 0;
        if (sorted.length > 0) {
          const parts = sorted[sorted.length - 1].version.split('.').map(Number);
          major = parts[0] || 0;
          minor = parts[1] || 0;
          patch = parts[2] || 0;
        }
        if (bumpType === 'major') return `${major + 1}.0.0`;
        if (bumpType === 'minor') return `${major}.${minor + 1}.0`;
        return `${major}.${minor}.${patch + 1}`;
      },

      latestReleaseName() {
        const sorted = this.releases
          .filter(r => /^\d+\.\d+/.test(r.version))
          .sort((a, b) => {
            const pa = a.version.split('.').map(Number), pb = b.version.split('.').map(Number);
            return (pa[0] - pb[0]) || (pa[1] - pb[1]) || ((pa[2] || 0) - (pb[2] || 0));
          });
        return sorted.length > 0 ? (sorted[sorted.length - 1].name || '') : '';
      },

      async createRelease() {
        if (!this.releaseForm.version.trim()) {
          this.showToast('Version is required', 'error');
          return;
        }
        this.releaseForm.saving = true;
        this.releaseForm.steps = {};
        try {
          if (this.publishStatus.github_configured) {
            // SSE streaming publish
            const body = JSON.stringify({
              version: this.releaseForm.version,
              name: this.releaseForm.name,
              notes: this.releaseForm.notes,
            });
            const response = await fetch('api.php?action=publish', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body,
            });
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let result = null;
            let error = null;

            while (true) {
              const { done, value } = await reader.read();
              if (done) break;
              buffer += decoder.decode(value, { stream: true });

              // Parse SSE events from buffer
              const parts = buffer.split('\n\n');
              buffer = parts.pop(); // keep incomplete chunk
              for (const part of parts) {
                let eventType = 'message', eventData = '';
                for (const line of part.split('\n')) {
                  if (line.startsWith('event: ')) eventType = line.slice(7);
                  else if (line.startsWith('data: ')) eventData = line.slice(6);
                }
                if (!eventData) continue;
                const data = JSON.parse(eventData);
                if (eventType === 'step') {
                  this.releaseForm.steps = { ...this.releaseForm.steps, [data.id]: data.status };
                } else if (eventType === 'done') {
                  result = data;
                } else if (eventType === 'error') {
                  error = data.error;
                }
              }
            }

            if (error) {
              this.showToast(error, 'error');
            } else if (result?.success) {
              this.releaseForm.result = { pr_url: result.pr_url, pr_number: result.pr_number };
              this.showToast(`Release v${this.releaseForm.version} created and published`, 'success');
              await this.loadReleases();
              this.loadPublishStatus();
            } else {
              this.showToast('Publish failed', 'error');
            }
          } else {
            // Local-only fallback
            const res = await this.api('create_release', 'POST', {
              version: this.releaseForm.version,
              name: this.releaseForm.name,
              notes: this.releaseForm.notes,
            });
            if (res.success) {
              this.releaseForm.result = {};
              this.showToast(`Release v${res.release.version} created`, 'success');
              await this.loadReleases();
            } else {
              this.showToast(res.error || 'Failed to create release', 'error');
            }
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        } finally {
          this.releaseForm.saving = false;
        }
      },

      // ── Users ────────────────────────────────────────────────────────────────

      openUserModal(mode, u = null) {
        this.userModal = {
          open: true,
          mode,
          form: u ? { ...u, password: '' } : { username: '', name: '', email: '', role: 'user', tsc_role: '', password: '' },
          saving: false,
          error: null,
        };
      },

      async saveUser() {
        this.userModal.error = null;
        this.userModal.saving = true;
        try {
          const action = this.userModal.mode === 'add' ? 'create_user' : 'update_user';
          const res = await this.api(action, 'POST', this.userModal.form);
          if (res.success) {
            this.userModal.open = false;
            this.showToast(this.userModal.mode === 'add' ? 'User created' : 'User updated', 'success');
            await this.loadUsers();
          } else {
            this.userModal.error = res.error || 'Failed to save user.';
          }
        } catch (e) {
          this.userModal.error = 'Network error.';
        } finally {
          this.userModal.saving = false;
        }
      },

      async deleteUser(u) {
        if (!confirm(`Delete user "${u.name}"? This cannot be undone.`)) return;
        try {
          const res = await this.api('delete_user', 'POST', { id: u.id });
          if (res.success) {
            this.showToast('User deleted', 'success');
            await this.loadUsers();
          } else {
            this.showToast(res.error || 'Failed to delete user', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        }
      },

      // ── GitHub / Publish ─────────────────────────────────────────────────────

      async loadPublishStatus() {
        try {
          const data = await this.api('get_publish_status');
          this.publishStatus = {
            unpublished_count: data.unpublished_count || 0,
            last_published_at: data.last_published_at,
            github_configured: data.github_configured || false,
          };
        } catch (e) { /* ignore — admin-only */ }
      },

      async loadCommitteeMembers() {
        try {
          const data = await this.api('get_committee_members');
          this.committeeMembers = data.members || [];
        } catch (e) { /* ignore */ }
      },

      async loadCharter() {
        try {
          const data = await this.api('get_charter');
          if (typeof marked !== 'undefined' && data.content) {
            // Strip {#anchor} suffixes from headings and use them as IDs
            let md = data.content.replace(/^(#{1,6}\s+.*?)\s*\{#([^}]+)\}\s*$/gm, (_, heading, id) => {
              return heading + ' {#' + id + '}';
            });
            // Configure marked to extract {#id} as heading IDs
            const renderer = new marked.Renderer();
            renderer.heading = function(text, level) {
              const match = text.match(/^(.*?)\s*\{#([^}]+)\}$/);
              if (match) {
                return `<h${level} id="${match[2]}">${match[1]}</h${level}>`;
              }
              const slug = text.toLowerCase().replace(/<[^>]*>/g, '').replace(/[^\w]+/g, '-').replace(/^-|-$/g, '');
              return `<h${level} id="${slug}">${text}</h${level}>`;
            };
            this.charterHtml = marked.parse(md, { renderer });
          } else {
            this.charterHtml = '<p class="text-muted">Could not render charter.</p>';
          }
        } catch (e) {
          this.charterHtml = '<p class="text-muted">Failed to load charter.</p>';
        }
      },

      async loadCodeOfConduct() {
        try {
          const data = await this.api('get_code_of_conduct');
          if (typeof marked !== 'undefined' && data.content) {
            let md = data.content.replace(/^(#{1,6}\s+.*?)\s*\{#([^}]+)\}\s*$/gm, (_, heading, id) => {
              return heading + ' {#' + id + '}';
            });
            const renderer = new marked.Renderer();
            renderer.heading = function(text, level) {
              const match = text.match(/^(.*?)\s*\{#([^}]+)\}$/);
              if (match) {
                return `<h${level} id="${match[2]}">${match[1]}</h${level}>`;
              }
              const slug = text.toLowerCase().replace(/<[^>]*>/g, '').replace(/[^\w]+/g, '-').replace(/^-|-$/g, '');
              return `<h${level} id="${slug}">${text}</h${level}>`;
            };
            this.codeOfConductHtml = marked.parse(md, { renderer });
          } else {
            this.codeOfConductHtml = '<p class="text-muted">Could not render Code of Conduct.</p>';
          }
        } catch (e) {
          this.codeOfConductHtml = '<p class="text-muted">Failed to load Code of Conduct.</p>';
        }
      },

      // ── Governance editor (admin) ─────────────────────────────────────────────
      renderMarkdown(md) {
        if (typeof marked === 'undefined') return '';
        return marked.parse(md || '', {});
      },

      async loadGovernance() {
        if (this.gov.loaded) return;
        try {
          const [c, k] = await Promise.all([this.api('get_charter'), this.api('get_code_of_conduct')]);
          this.gov.charter = c.raw ?? '';
          this.gov.coc = k.raw ?? '';
          this.gov.loaded = true;
        } catch (e) { /* ignore */ }
      },

      async saveCharter() {
        this.gov.savingCharter = true;
        try {
          const res = await this.api('save_charter', 'POST', { content: this.gov.charter });
          if (res.success) { this.showToast('Charter saved', 'success'); this.charterHtml = ''; }
          else { this.showToast(res.error || 'Failed to save charter', 'error'); }
        } finally { this.gov.savingCharter = false; }
      },

      async saveCodeOfConduct() {
        this.gov.savingCoc = true;
        try {
          const res = await this.api('save_code_of_conduct', 'POST', { content: this.gov.coc });
          if (res.success) { this.showToast('Code of Conduct saved', 'success'); this.codeOfConductHtml = ''; }
          else { this.showToast(res.error || 'Failed to save Code of Conduct', 'error'); }
        } finally { this.gov.savingCoc = false; }
      },

      async loadGithubConfig() {
        try {
          const data = await this.api('get_github_config');
          const cfg = data.config || {};
          this.githubSettings.app_id = cfg.app_id || '';
          this.githubSettings.installation_id = cfg.installation_id || '';
          this.githubSettings.owner = cfg.owner || '';
          this.githubSettings.repo = cfg.repo || '';
          this.githubSettings.key_exists = cfg.key_exists || false;
          this.githubSettings.private_key = '';
          this.githubSettings.test_result = null;
          this.githubSettings.deepl_key_exists = cfg.deepl_key_exists || false;
          this.githubSettings.deepl_api_key = '';
          this.githubSettings.translation_languages = cfg.translation_languages || '';
          this.githubSettings.deepl_test_result = null;
          this.githubSettings.public_api_token = cfg.public_api_token || '';
        } catch (e) { /* ignore */ }
      },

      // True when a settings section has been configured (drives the tab status dots).
      settingsConfigured(section) {
        if (section === 'github') return !!this.publishStatus.github_configured;
        if (section === 'translations') return !!(this.githubSettings.deepl_key_exists && (this.githubSettings.translation_languages || '').trim());
        if (section === 'public') return !!this.githubSettings.public_api_token;
        return true;
      },

      async deleteConfigSection(section, label) {
        if (!confirm('Delete all ' + label + ' settings? This cannot be undone.')) return;
        const res = await this.api('delete_config', 'POST', { section });
        if (res.success) {
          this.showToast(label + ' settings deleted', 'success');
          await this.loadGithubConfig();
          this.loadPublishStatus();
        } else {
          this.showToast(res.error || 'Failed to delete settings', 'error');
        }
      },

      async loadProfileConfig() {
        try {
          const data = await this.api('get_profile');
          this.profileAvailable = data.available || [];
          const p = data.profile || {};
          const subObj = p.subframeworks || {};
          const subs = Object.keys(subObj).map((slug) => {
            const s = subObj[slug] || {};
            const lv = (n) => { const L = (s.levels || {})[n] || {}; return { label: L.label || '', plural: L.plural || '' }; };
            return { slug, label: s.label || '', id_prefix: s.id_prefix || '', kill_chain_name: s.kill_chain_name || '',
                     levels: { tactic: lv('tactic'), technique: lv('technique'), subtechnique: lv('subtechnique') } };
          });
          this.profileForm = {
            product_name: p.product_name || '', framework_name: p.framework_name || '',
            org_name: p.org_name || '', org_short: p.org_short || '', org_description: p.org_description || '',
            source_name: p.source_name || '', artifact_slug: p.artifact_slug || '', base_url: p.base_url || '',
            stix_property_prefix: p.stix_property_prefix || '',
            extension_definition_id: p.extension_definition_id || '', extension_definition_name: p.extension_definition_name || '',
            identity_ref: p.identity_ref || '',
            subs,
          };
          this.instanceSourceUrl = data.instance_source_url || '';
          this.upstreamSourceUrl = data.upstream_source_url || '';
          this.profileError = ''; this.profileImport = ''; this.instanceSourceMsg = '';
        } catch (e) { /* ignore */ }
      },

      async saveInstanceSource() {
        this.instanceSourceSaving = true; this.instanceSourceMsg = '';
        try {
          const res = await this.api('save_instance_source_url', 'POST', { url: this.instanceSourceUrl.trim() });
          if (res.success) { this.showToast('Source URL saved — reloading', 'success'); setTimeout(() => location.reload(), 700); }
          else { this.instanceSourceMsg = res.error || 'Failed to save'; }
        } finally { this.instanceSourceSaving = false; }
      },

      async importProfile() {
        if (!this.profileImport) return;
        if (!confirm('Import "' + this.profileImport + '"?\n\nThis replaces the current profile. Any stored data belonging to sub-frameworks that are not in the imported profile will be permanently deleted. This cannot be undone.')) return;
        const res = await this.api('save_profile', 'POST', { import: this.profileImport });
        if (res.success) { this.showToast('Profile imported — reloading', 'success'); setTimeout(() => location.reload(), 700); }
        else { this.profileError = res.error || 'Import failed'; }
      },

      // Generate a STIX-style identifier "<prefix>--<uuid v4>".
      genUuid(prefix) {
        let uuid;
        if (self.crypto && crypto.randomUUID) { uuid = crypto.randomUUID(); }
        else {
          uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = crypto.getRandomValues(new Uint8Array(1))[0] % 16;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
          });
        }
        return prefix + '--' + uuid;
      },

      slugify(s) {
        return (s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
      },

      emptySub() {
        return { slug: '', label: '', id_prefix: '', kill_chain_name: '',
                 levels: { tactic: { label: '', plural: '' }, technique: { label: '', plural: '' }, subtechnique: { label: '', plural: '' } } };
      },

      addSubframework() {
        this.profileForm.subs.push(this.emptySub());
      },

      removeSubframework(i) {
        if (this.profileForm.subs.length <= 1) { this.showToast('At least one sub-framework is required', 'error'); return; }
        const s = this.profileForm.subs[i];
        const named = (s.label || s.slug || 'this sub-framework');
        if (!confirm('Remove ' + named + '?\n\nWhen you save the profile, ALL of its stored data (tactics, techniques, sub-techniques and change proposals) will be permanently deleted. This cannot be undone.')) return;
        this.profileForm.subs.splice(i, 1);
      },

      // Fill blank technical fields from the human-readable names (never overwrites set values).
      deriveProfileTechnical() {
        const f = this.profileForm;
        const dash = (s) => (s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        const fw = dash(f.framework_name) || 'framework';
        if (!f.source_name)    f.source_name = (f.framework_name || 'FRAMEWORK').toUpperCase();
        if (!f.artifact_slug)  f.artifact_slug = fw + '-framework';
        if (!f.stix_property_prefix) f.stix_property_prefix = 'x_' + fw.replace(/-/g, '_');
        if (!f.extension_definition_name) f.extension_definition_name = (f.framework_name || 'Framework') + ' Membership';
        for (const sf of f.subs) {
          if (!sf.kill_chain_name) sf.kill_chain_name = fw + '-' + (dash(sf.label) || dash(sf.slug) || 'sub');
        }
        if (!f.extension_definition_id) f.extension_definition_id = this.genUuid('extension-definition');
        if (!f.identity_ref) f.identity_ref = this.genUuid('identity');
        this.showToast('Filled blank technical fields', 'success');
      },

      async saveProfile() {
        const f = this.profileForm;
        this.profileError = '';
        this.settingsTab = 'profile';
        if (!f.framework_name.trim()) { this.profileError = 'Framework name is required'; return; }
        if (!f.subs.length) { this.profileError = 'At least one sub-framework is required'; return; }
        const subframeworks = {};
        const usedSlugs = {}, usedPrefix = {}, usedKc = {};
        for (const sf of f.subs) {
          if (!sf.id_prefix.trim() || !sf.kill_chain_name.trim()) {
            this.profileError = 'Each sub-framework needs an ID prefix and a kill-chain name'; return;
          }
          // Stable slug: keep existing, else derive from label, else index; ensure unique.
          let slug = this.slugify(sf.slug) || this.slugify(sf.label) || ('framework_' + (Object.keys(subframeworks).length + 1));
          let base = slug, n = 2;
          while (usedSlugs[slug]) { slug = base + '_' + (n++); }
          usedSlugs[slug] = true;
          const pfx = sf.id_prefix.trim().toUpperCase();
          const kc = sf.kill_chain_name.trim();
          if (usedPrefix[pfx]) { this.profileError = 'Duplicate ID prefix: ' + pfx; return; }
          if (usedKc[kc])      { this.profileError = 'Duplicate kill-chain name: ' + kc; return; }
          usedPrefix[pfx] = true; usedKc[kc] = true;
          const levels = {};
          for (const lvl of this.levelKeys) {
            const L = sf.levels[lvl];
            levels[lvl] = { label: L.label.trim() || lvl, plural: L.plural.trim() || ((L.label.trim() || lvl) + 's') };
          }
          subframeworks[slug] = { label: sf.label.trim() || slug, id_prefix: pfx, kill_chain_name: kc, levels };
        }
        const p = {
          product_name: f.product_name.trim() || 'Framework Manager',
          framework_name: f.framework_name.trim(),
          org_name: f.org_name.trim(), org_short: f.org_short.trim(), org_description: f.org_description.trim(),
          source_name: (f.source_name.trim() || f.framework_name.trim().toUpperCase()),
          artifact_slug: f.artifact_slug.trim() || 'framework',
          base_url: f.base_url.trim(),
          stix_property_prefix: f.stix_property_prefix.trim() || 'x_framework',
          extension_definition_id: f.extension_definition_id.trim() || this.genUuid('extension-definition'),
          extension_definition_name: f.extension_definition_name.trim() || (f.framework_name.trim() + ' Membership'),
          identity_ref: f.identity_ref.trim() || this.genUuid('identity'),
          subframeworks,
        };
        const res = await this.api('save_profile', 'POST', { profile: p });
        if (res.success) { this.showToast('Profile saved — reloading', 'success'); setTimeout(() => location.reload(), 700); }
        else { this.profileError = res.error || 'Save failed'; }
      },

      async saveGithubConfig() {
        this.githubSettings.saving = true;
        try {
          const res = await this.api('save_github_config', 'POST', {
            app_id: this.githubSettings.app_id,
            installation_id: this.githubSettings.installation_id,
            owner: this.githubSettings.owner,
            repo: this.githubSettings.repo,
            private_key: this.githubSettings.private_key,
            deepl_api_key: this.githubSettings.deepl_api_key,
            translation_languages: this.githubSettings.translation_languages,
            public_api_token: this.githubSettings.public_api_token,
          });
          if (res.success) {
            this.showToast('Configuration saved', 'success');
            this.githubSettings.key_exists = this.githubSettings.key_exists || !!this.githubSettings.private_key;
            this.githubSettings.private_key = '';
            this.githubSettings.deepl_key_exists = this.githubSettings.deepl_key_exists || !!this.githubSettings.deepl_api_key;
            this.githubSettings.deepl_api_key = '';
          } else {
            this.showToast(res.error || 'Failed to save configuration', 'error');
          }
        } catch (e) {
          this.showToast('Network error', 'error');
        } finally {
          this.githubSettings.saving = false;
        }
      },

      async testGithubConnection() {
        this.githubSettings.testing = true;
        this.githubSettings.test_result = null;
        try {
          const res = await this.api('test_github_connection', 'POST');
          this.githubSettings.test_result = res;
        } catch (e) {
          this.githubSettings.test_result = { success: false, error: 'Network error' };
        } finally {
          this.githubSettings.testing = false;
        }
      },

      async testDeeplConnection() {
        this.githubSettings.deepl_testing = true;
        this.githubSettings.deepl_test_result = null;
        try {
          const res = await this.api('test_deepl_connection', 'POST', {
            deepl_api_key: this.githubSettings.deepl_api_key,
          });
          this.githubSettings.deepl_test_result = res;
        } catch (e) {
          this.githubSettings.deepl_test_result = { success: false, error: 'Network error' };
        } finally {
          this.githubSettings.deepl_testing = false;
        }
      },


      // ── Logout ───────────────────────────────────────────────────────────────

      async logout() {
        await this.api('logout', 'POST');
        window.location.href = 'index.php';
      },

      // ── Helpers ──────────────────────────────────────────────────────────────

      showToast(message, type = 'info') {
        const id = Date.now() + Math.random();
        this.toasts.push({ id, message, type });
        setTimeout(() => {
          const el = document.getElementById('toast-' + id);
          if (el) el.classList.add('leaving');
          setTimeout(() => {
            this.toasts = this.toasts.filter(t => t.id !== id);
          }, 200);
        }, 4000);
      },

      formatDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d)) return iso;
        const now = new Date();
        const diff = Math.floor((now - d) / 1000);
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 7 * 86400) return Math.floor(diff / 86400) + 'd ago';
        return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
      },

      getDiff(before, after) {
        if (!before || !after) return [];
        const fields = ['name', 'description', 'tactic_shortnames', 'platforms', 'related_reports', 'related_techniques'];
        const diffs = [];
        for (const key of fields) {
          const bv = before[key];
          const av = after[key];
          const bs = JSON.stringify(bv);
          const as_ = JSON.stringify(av);
          if (bs !== as_) {
            let displayBefore = bv;
            let displayAfter = av;
            if (key === 'related_reports') {
              displayBefore = (bv || []).map(r => r.url).join(', ') || '—';
              displayAfter = (av || []).map(r => r.url).join(', ') || '—';
            } else if (key === 'related_techniques') {
              displayBefore = bv || [];
              displayAfter = av || [];
            }
            diffs.push({ key, before: displayBefore, after: displayAfter });
          }
        }
        return diffs;
      },
    }));
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/marked@9.1.6/marked.min.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>

</body>
</html>
