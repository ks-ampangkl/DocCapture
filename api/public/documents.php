<!DOCTYPE html>
<html>
<head>
  <title>DocCapture — Documents</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen p-8">
  <div class="max-w-5xl mx-auto">
    <div class="mb-4 flex gap-4 text-sm">
      <a href="/documents.php" class="font-semibold">Documents</a>
      <a href="/upload.php" class="text-blue-600 hover:underline">Upload</a>
      <a href="/chat.php" class="text-blue-600 hover:underline">Chat</a>
      <a href="/dashboard.php" class="text-blue-600 hover:underline">Dashboard</a>
    </div>

    <div class="flex justify-between items-center mb-4">
      <h1 class="text-xl font-semibold">Tracked Documents</h1>
      <a href="/upload.php" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">
        + Upload Document
      </a>
    </div>

    <div class="bg-white border rounded-lg shadow-sm overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-gray-100 text-left">
          <tr>
            <th class="px-4 py-2">ID</th>
            <th class="px-4 py-2">Name</th>
            <th class="px-4 py-2">Owner</th>
            <th class="px-4 py-2">Category</th>
            <th class="px-4 py-2">Version</th>
            <th class="px-4 py-2">Status</th>
            <th class="px-4 py-2">Pages</th>
            <th class="px-4 py-2">Uploaded</th>
          </tr>
        </thead>
        <tbody id="doc-rows">
          <tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    async function loadDocuments() {
      const tbody = document.getElementById('doc-rows');
      try {
        const res = await fetch('/api/documents');
        const data = await res.json();

        if (!data.data || data.data.length === 0) {
          tbody.innerHTML = '<tr><td colspan="8" class="px-4 py-6 text-center text-gray-400">No documents uploaded yet.</td></tr>';
          return;
        }

        tbody.innerHTML = data.data.map(doc => {
          const statusColor = doc.approval_status === 'Approved'
            ? 'bg-green-100 text-green-800'
            : 'bg-yellow-100 text-yellow-800';
          const uploadDate = doc.upload_date
            ? new Date(doc.upload_date).toLocaleDateString()
            : '—';

          return `
            <tr class="border-t">
              <td class="px-4 py-2">${doc.id}</td>
              <td class="px-4 py-2 font-medium">${doc.name}</td>
              <td class="px-4 py-2">${doc.owner}</td>
              <td class="px-4 py-2">${doc.category ?? '—'}</td>
              <td class="px-4 py-2">${doc.version ?? '—'}</td>
              <td class="px-4 py-2">
                <span class="px-2 py-1 rounded text-xs ${statusColor}">${doc.approval_status}</span>
              </td>
              <td class="px-4 py-2">${doc.page_count ?? '—'}</td>
              <td class="px-4 py-2 text-gray-500">${uploadDate}</td>
            </tr>
          `;
        }).join('');
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="8" class="px-4 py-6 text-center text-red-600">Failed to load: ${err.message}</td></tr>`;
      }
    }

    loadDocuments();
  </script>
</body>
</html>