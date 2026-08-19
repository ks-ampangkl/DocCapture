import fitz  # PyMuPDF
import os
from flask import Flask, request, jsonify

app = Flask(__name__)

# This must point to the SAME uploads folder your PHP app writes to.
# Adjust the path if your two project folders sit somewhere else relative
# to each other.
UPLOADS_ROOT = r"C:\laragon\www\doccapture-api"


@app.route("/", methods=["GET"])
def health_check():
    return jsonify({"service": "DocCapture PDF Sidecar", "status": "ok"})


@app.route("/process-pdf", methods=["POST"])
def process_pdf():
    body = request.get_json(silent=True) or {}
    relative_path = body.get("storage_path")

    if not relative_path:
        return jsonify({"error": "storage_path is required"}), 400

    full_path = os.path.join(UPLOADS_ROOT, relative_path)

    if not os.path.isfile(full_path):
        return jsonify({"error": f"File not found: {relative_path}"}), 404

    try:
        doc = fitz.open(full_path)
    except Exception as e:
        return jsonify({"error": f"Could not open PDF: {str(e)}"}), 400

    pages = []
    for page_number, page in enumerate(doc, start=1):
        text = page.get_text()
        pages.append({"page_number": page_number, "text": text})

    page_count = doc.page_count
    doc.close()

    return jsonify({
        "page_count": page_count,
        "pages": pages
    })


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=True)