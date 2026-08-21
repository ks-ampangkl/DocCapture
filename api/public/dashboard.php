<!DOCTYPE html>
<html>
<head>
  <title>DocCapture — Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen p-8">
  <div class="max-w-5xl mx-auto mb-4 flex gap-4 text-sm">
    <a href="/documents.php" class="text-blue-600 hover:underline">Documents</a>
    <a href="/upload.php" class="text-blue-600 hover:underline">Upload</a>
    <a href="/chat.php" class="text-blue-600 hover:underline">Chat</a>
    <a href="/dashboard.php" class="font-semibold">Dashboard</a>
  </div>

  <div class="max-w-5xl mx-auto">
    <h1 class="text-xl font-semibold mb-4">Knowledge Integrity Dashboard</h1>

    <!-- Aggregate counter cards -->
    <div class="grid grid-cols-3 md:grid-cols-6 gap-4 mb-6" id="counter-cards">
      <div class="bg-white border rounded-lg shadow-sm p-4 text-center">
        <div class="text-2xl font-bold text-blue-600" id="stat-integrity">--</div>
        <div class="text-xs text-gray-500 mt-1">Overall Integrity</div>
      </div>
      <div class="bg-white border rounded-lg shadow-sm p-4 text-center">
        <div class="text-2xl font-bold text-gray-800" id="stat-docs">--</div>
        <div class="text-xs text-gray-500 mt-1">Documents</div>
      </div>
      <div class="bg-white border rounded-lg shadow-sm p-4 text-center">
        <div class="text-2xl font-bold text-red-600" id="stat-conflicts">--</div>
        <div class="text-xs text-gray-500 mt-1">⚠ Conflicts</div>
      </div>
      <div class="bg-white border rounded-lg shadow-sm p-4 text-center">
        <div class="text-2xl font-bold text-yellow-600" id="stat-outdated">--</div>
        <div class="text-xs text-gray-500 mt-1">⚠ Outdated</div>
      </div>
      <div class="bg-white border rounded-lg shadow-sm p-4 text-center">
        <div class="text-2xl font-bold text-orange-600" id="stat-missing">--</div>
        <div class="text-xs text-gray-500 mt-1">Missing Docs</div>
      </div>
      <div class="bg-white border rounded-lg shadow-sm p-4 text-center">
        <div class="text-2xl font-bold text-red-700" id="stat-highrisk">--</div>
        <div class="text-xs text-gray-500 mt-1">High Risk</div>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <!-- High-risk documents list -->
      <div class="bg-white border rounded-lg shadow-sm p-4">
        <h2 class="font-semibold text-sm text-gray-700 mb-2">High Risk Documents</h2>
        <ul id="high-risk-list" class="text-sm space-y-1 text-gray-600">
          <li class="text-gray-400">Loading...</li>
        </ul>
      </div>

      <!-- Recent conflicts list -->
      <div class="bg-white border rounded-lg shadow-sm p-4">
        <h2 class="font-semibold text-sm text-gray-700 mb-2">Recent Conflicts</h2>
        <ul id="conflicts-list" class="text-sm space-y-2 text-gray-600">
          <li class="text-gray-400">Loading...</li>
        </ul>
      </div>
    </div>

    <!-- Outdated documents list -->
    <div class="bg-white border rounded-lg shadow-sm p-4 mt-4">
      <h2 class="font-semibold text-sm text-gray-700 mb-2">Outdated Documents</h2>
      <ul id="outdated-list" class="text-sm space-y-1 text-gray-600">
        <li class="text-gray-400">Loading...</li>
      </ul>
    </div>
  </div>

  <script>
    async function loadDashboard() {
      try {
        const res = await fetch('/api/dashboard');
        const data = await res.json();
        const s = data.summary;

        document.getElementById('stat-integrity').textContent = s.overall_integrity + '%';
        document.getElementById('stat-docs').textContent = s.total_documents;
        document.getElementById('stat-conflicts').textContent = s.conflicts;
        document.getElementById('stat-outdated').textContent = s.outdated;
        document.getElementById('stat-missing').textContent = s.missing_docs;
        document.getElementById('stat-highrisk').textContent = s.high_risk;

        const highRiskList = document.getElementById('high-risk-list');
        highRiskList.innerHTML = data.high_risk_docs.length
          ? data.high_risk_docs.map(d => `<li>⚠ ${d.name}</li>`).join('')
          : '<li class="text-gray-400">None found</li>';

        const conflictsList = document.getElementById('conflicts-list');
        conflictsList.innerHTML = data.recent_conflicts.length
          ? data.recent_conflicts.map(c => `
              <li class="border-l-4 border-red-300 pl-2">
                <div class="font-medium">${c.document_name || 'Unknown'} vs ${c.conflicting_document_name || 'Unknown'}</div>
                <div class="text-xs text-gray-500">${c.description} (${c.risk_level})</div>
              </li>`).join('')
          : '<li class="text-gray-400">No conflicts detected</li>';

        const outdatedList = document.getElementById('outdated-list');
        outdatedList.innerHTML = data.outdated_docs.length
          ? data.outdated_docs.map(d => `<li>⚠ ${d.name} <span class="text-xs text-gray-400">(uploaded ${d.upload_date})</span></li>`).join('')
          : '<li class="text-gray-400">No outdated documents</li>';

      } catch (err) {
        console.error('Dashboard load failed:', err);
      }
    }

    loadDashboard();
  </script>
</body>
</html>