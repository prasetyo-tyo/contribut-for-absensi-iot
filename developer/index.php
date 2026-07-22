<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dev Console — Absensi IoT</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔧</text></svg>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="style.css">
</head>
<body>
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <h1>Dev Console</h1>
      <span>Absensi IoT Platform</span>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-label">Monitoring</div>
      <a href="#" class="active" data-view="overview">
        <span class="icon">📊</span> Overview
      </a>
      <a href="#" data-view="logs">
        <span class="icon">📋</span> API Logs
      </a>
      <a href="#" data-view="invalid">
        <span class="icon">🚫</span> Invalid Cards
      </a>
      <div class="nav-label">Database</div>
      <a href="#" data-view="karyawan">
        <span class="icon">👥</span> Karyawan
      </a>
      <div class="nav-label">Diagnostics</div>
      <a href="#" data-view="diagnostics">
        <span class="icon">🔬</span> Card Scanner
      </a>
    </nav>
    <div class="sidebar-footer">
      <a href="../apps/index.php">← Back to Main App</a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <!-- TOPBAR -->
    <div class="topbar">
      <div class="topbar-left">
        <h2 id="pageTitle">Overview</h2>
        <div class="live-dot" id="liveIndicator" title="Auto-refresh active"></div>
      </div>
      <div class="topbar-right">
        <span style="font-size:.75rem;color:var(--text-muted)" id="lastUpdate">—</span>
        <button class="btn btn-sm" id="autoRefreshBtn" onclick="toggleAutoRefresh()">⏸ Pause</button>
        <button class="btn btn-sm btn-primary" onclick="loadCurrentView()">⟳ Refresh</button>
      </div>
    </div>

    <!-- CONTENT -->
    <div class="content" id="appContent">
      <!-- Filled by JS -->
    </div>
  </main>

  <!-- MODAL: Log Detail -->
  <div class="modal-overlay" id="modalOverlay" onclick="closeModal()">
    <div class="modal" onclick="event.stopPropagation()">
      <div class="modal-header">
        <h3 id="modalTitle">Detail</h3>
        <button class="btn btn-sm" onclick="closeModal()">✕</button>
      </div>
      <div class="modal-body" id="modalBody"></div>
    </div>
  </div>

  <script src="app.js"></script>
</body>
</html>
