<!DOCTYPE html>
<html>
<head>
  <title>DocCapture — Chat</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen p-8">
  <div class="max-w-2xl mx-auto mb-4 flex gap-4 text-sm">
    <a href="/documents.php" class="text-blue-600 hover:underline">Documents</a>
    <a href="/upload.php" class="text-blue-600 hover:underline">Upload</a>
    <a href="/chat.php" class="font-semibold">Chat</a>
    <a href="/dashboard.php" class="font-semibold">Dashboard</a>
  </div>

  <div class="max-w-5xl mx-auto grid grid-cols-3 gap-4">
    <!-- Left: chat -->
    <div class="col-span-2 border rounded-lg shadow-sm bg-white flex flex-col">
      <div id="messages" class="h-96 overflow-y-auto p-4 space-y-3"></div>
      <form id="chat-form" class="flex border-t p-3 gap-2">
        <input id="chat-input" type="text" placeholder="Ask about your documents..."
               class="flex-1 border rounded px-3 py-2" required>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Send</button>
      </form>
    </div>

    <!-- Right: evidence panel -->
    <div class="border rounded-lg shadow-sm bg-white p-4">
      <h2 class="font-semibold mb-3 text-sm text-gray-700">Evidence</h2>
      <div id="evidence-panel" class="space-y-3 text-sm text-gray-500">
        Ask a question to see sources here.
      </div>
    </div>
  </div>

 <script>
document.getElementById('chat-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const input = document.getElementById('chat-input');
  const messages = document.getElementById('messages');
  const evidencePanel = document.getElementById('evidence-panel');
  const query = input.value;

  messages.innerHTML += `<div class="text-right"><span class="bg-blue-100 px-3 py-2 rounded inline-block">${query}</span></div>`;
  input.value = '';

  const res = await fetch('/api/chat/submit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query })
  });
  const data = await res.json();
  const score = data.integrity_score || {};

  let conflictBanner = '';
  if (data.is_conflicting) {
    const risk = (data.conflict_details?.risk_level || 'LOW').toUpperCase();
    const bannerColor = risk === 'HIGH' ? 'bg-red-100 border-red-400 text-red-800' : 'bg-yellow-100 border-yellow-400 text-yellow-800';
    const sources = (data.conflict_details?.conflicting_sources || []).join(' vs. ');
    conflictBanner = `
      <div class="border-l-4 ${bannerColor} p-3 rounded mb-2 text-sm">
        <strong>⚠ Conflict Detected (${risk} risk)</strong><br>
        ${data.conflict_details?.description || ''}<br>
        <span class="text-xs italic">Sources: ${sources}</span>
      </div>`;
  }

  const meterRow = (label, value, color) => `
    <div class="flex items-center gap-2 text-xs mb-1">
      <span class="w-24 text-gray-600">${label}</span>
      <div class="flex-1 bg-gray-200 rounded h-2">
        <div class="${color} h-2 rounded" style="width: ${value}%"></div>
      </div>
      <span class="w-8 text-right text-gray-500">${Math.round(value)}</span>
    </div>`;

  const scorePanel = `
    <div class="mt-2 p-2 border rounded bg-white">
      <div class="text-xs font-semibold text-gray-700 mb-1">Knowledge Integrity Score</div>
      ${meterRow('Confidence', score.confidence ?? 0, 'bg-blue-500')}
      ${meterRow('Freshness', score.freshness ?? 0, 'bg-green-500')}
      ${meterRow('Consistency', score.consistency ?? 0, 'bg-purple-500')}
      ${meterRow('Completeness', score.completeness ?? 0, 'bg-teal-500')}
      ${meterRow('Approval', score.approval ?? 0, 'bg-indigo-500')}
      <div class="border-t mt-2 pt-1 flex justify-between text-sm font-semibold">
        <span>Overall</span><span>${Math.round(score.overall ?? 0)}%</span>
      </div>
    </div>`;

  messages.innerHTML += `
    <div class="text-left">
      ${conflictBanner}
      <span class="bg-gray-100 px-3 py-2 rounded inline-block">${data.answer || data.error}</span>
      ${scorePanel}
    </div>`;
  messages.scrollTop = messages.scrollHeight;

  if (data.evidence && data.evidence.length) {
    evidencePanel.innerHTML = data.evidence.map(ev => `
      <div class="border rounded p-2">
        <div class="font-medium text-gray-800">${ev.doc_name}</div>
        <div class="text-xs text-gray-500 mb-1">Page ${ev.page}</div>
        <div class="text-xs text-gray-600 italic">"${ev.excerpt}"</div>
      </div>`).join('');
  } else {
    evidencePanel.innerHTML = '<div class="text-gray-400">No sources found.</div>';
  }
});
</script>
</body>
</html>