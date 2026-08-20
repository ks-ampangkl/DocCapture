<!DOCTYPE html>
<html>
<head>
  <title>DocCapture — Upload Document</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen p-8">
    <body class="bg-gray-50 min-h-screen p-8">
  <div class="max-w-2xl mx-auto mb-4 flex gap-4 text-sm">
    <a href="/documents.php" class="text-blue-600 hover:underline">Documents</a>
    <a href="/upload.php" class="font-semibold">Upload</a>
    <a href="/chat.php" class="text-blue-600 hover:underline">Chat</a>
  </div>

  <div class="max-w-2xl mx-auto bg-white border rounded-lg shadow-sm p-6">
    <h1 class="text-xl font-semibold mb-4">Upload Document</h1>

    <div id="status-msg" class="hidden mb-4 p-3 rounded text-sm"></div>

    <form id="upload-form" class="grid grid-cols-2 gap-4">
      <div class="col-span-2">
        <label class="block text-sm font-medium mb-1">File (PDF only)</label>
        <input type="file" name="file" accept="application/pdf" required
               class="w-full border rounded px-3 py-2">
      </div>

      <div class="col-span-2">
        <label class="block text-sm font-medium mb-1">Document Name</label>
        <input type="text" name="name" placeholder="e.g. HR Leave Policy 2026"
               class="w-full border rounded px-3 py-2">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Owner</label>
        <select name="owner" required class="w-full border rounded px-3 py-2">
          <option value="">Select owner...</option>
          <option value="Human Resources">Human Resources</option>
          <option value="Finance">Finance</option>
          <option value="Legal">Legal</option>
          <option value="IT">IT</option>
          <option value="Operations">Operations</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Approval Status</label>
        <select name="approval_status" class="w-full border rounded px-3 py-2">
          <option value="Draft">Draft</option>
          <option value="Approved">Approved</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Category</label>
        <input type="text" name="category" placeholder="e.g. Policy"
               class="w-full border rounded px-3 py-2">
      </div>

      <div>
        <label class="block text-sm font-medium mb-1">Version</label>
        <input type="text" name="version" placeholder="e.g. 2026"
               class="w-full border rounded px-3 py-2">
      </div>

      <div class="col-span-2 flex justify-end items-center mt-2">
        <button type="submit" id="submit-btn"
                class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700">
          Upload
        </button>
      </div>
    </form>
  </div>

  <script>
    const form = document.getElementById('upload-form');
    const statusMsg = document.getElementById('status-msg');
    const submitBtn = document.getElementById('submit-btn');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      submitBtn.disabled = true;
      submitBtn.textContent = 'Uploading...';
      statusMsg.classList.add('hidden');

      const formData = new FormData(form);

      try {
        const res = await fetch('/api/documents/upload', {
          method: 'POST',
          body: formData
        });
        const data = await res.json();

        if (res.ok) {
          statusMsg.textContent = `Uploaded: ${data.data.name} (ID ${data.data.id})`;
          statusMsg.className = 'mb-4 p-3 rounded text-sm bg-green-100 text-green-800';
          form.reset();
        } else {
          const errText = data.errors ? JSON.stringify(data.errors) : (data.error || 'Upload failed');
          statusMsg.textContent = errText;
          statusMsg.className = 'mb-4 p-3 rounded text-sm bg-red-100 text-red-800';
        }
      } catch (err) {
        statusMsg.textContent = 'Could not reach server: ' + err.message;
        statusMsg.className = 'mb-4 p-3 rounded text-sm bg-red-100 text-red-800';
      }

      statusMsg.classList.remove('hidden');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Upload';
    });
  </script>
</body>
</html>