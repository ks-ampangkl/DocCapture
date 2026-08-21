import fitz  # PyMuPDF
import os
import json
import re
import pickle
from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer
import faiss
from dotenv import load_dotenv
import google.generativeai as genai


app = Flask(__name__)
load_dotenv(os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env"))
genai.configure(api_key=os.environ.get("GEMINI_API_KEY"))


# This must point to the SAME uploads folder your PHP app writes to.
# Adjust the path if your two project folders sit somewhere else relative
# to each other.
UPLOADS_ROOT = r"C:\laragon\www\doccapture\api"

# --- FAISS / embedding setup ---
model = SentenceTransformer('all-MiniLM-L6-v2')
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
INDEX_PATH = os.path.join(BASE_DIR, "faiss_index", "index.bin")
META_PATH = os.path.join(BASE_DIR, "faiss_index", "meta.pkl")



@app.route("/rag-query", methods=["POST"])
def rag_query():
    data = request.get_json()
    query = data.get("query", "")

    if not query or not os.path.exists(INDEX_PATH):
        return jsonify({"answer": "No documents indexed yet.", "confidence": 0, "evidence": []})

    query_vec = model.encode([query], convert_to_numpy=True)

    index = faiss.read_index(INDEX_PATH)
    with open(META_PATH, "rb") as f:
        metadata = pickle.load(f)

    k = min(4, len(metadata))
    distances, indices = index.search(query_vec, k)
    retrieved = [metadata[i] for i in indices[0] if i < len(metadata)]

    result = call_llm(query, retrieved)

    return jsonify({
        "answer": result.get("answer", ""),
        "confidence": result.get("confidence", 0),
        "evidence": result.get("evidence", []),
        "is_conflicting": result.get("is_conflicting", False),
        "conflict_details": result.get("conflict_details", {})
    })

@app.route("/", methods=["GET"])
def health_check():
    return jsonify({"service": "DocCapture PDF Sidecar", "status": "ok"})


def chunk_text(text, page_number, doc_id, doc_name,chunk_size=300, overlap=50):
    words = text.split()
    chunks = []
    for i in range(0, len(words), chunk_size - overlap):
        chunk_words = words[i:i + chunk_size]
        if not chunk_words:
            continue
        chunks.append({
            "doc_id": doc_id,
            "doc_name": doc_name,
            "page_number": page_number,
            "text": " ".join(chunk_words)
        })
    return chunks


def embed_and_index(chunks):
    if not chunks:
        return
    os.makedirs("faiss_index", exist_ok=True)
    texts = [c["text"] for c in chunks]
    embeddings = model.encode(texts, convert_to_numpy=True)

    if os.path.exists(INDEX_PATH):
        index = faiss.read_index(INDEX_PATH)
        with open(META_PATH, "rb") as f:
            metadata = pickle.load(f)
    else:
        dim = embeddings.shape[1]
        index = faiss.IndexFlatL2(dim)
        metadata = []

    index.add(embeddings)
    metadata.extend(chunks)

    faiss.write_index(index, INDEX_PATH)
    with open(META_PATH, "wb") as f:
        pickle.dump(metadata, f)


@app.route("/process-pdf", methods=["POST"])
def process_pdf():
    body = request.get_json(silent=True) or {}
    relative_path = body.get("storage_path")
    doc_id = body.get("doc_id")  # NEW — must be passed in from Slim
    doc_name = body.get("doc_name","Untitled")


    if not relative_path:
        return jsonify({"error": "storage_path is required"}), 400

    if not doc_id:
        return jsonify({"error": "doc_id is required"}), 400

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

    # --- NEW: chunk + embed into FAISS ---
    all_chunks = []
    for page in pages:
        page_chunks = chunk_text(page["text"], page["page_number"], doc_id,doc_name)
        all_chunks.extend(page_chunks)

    embed_and_index(all_chunks)

    return jsonify({
        "page_count": page_count,
        "pages": pages,
        "chunks_indexed": len(all_chunks)   # handy for confirming it worked
    })



def call_llm(query, retrieved_chunks):
    context = "\n\n".join(
        f"[Source: {c.get('doc_name', 'Unknown')}, Page {c.get('page_number', '?')}]: {c['text']}"
        for c in retrieved_chunks
    )

    prompt = f"""Answer the question using ONLY the context below.

Context:
{context}

Question: {query}

Instructions:
- If multiple document versions give different answers, explain the history briefly, but the "answer" field must end with a clear, standalone conclusion stating what currently applies, in this exact format:
  "In conclusion, [direct answer to the question]. This is because [short reason]."
- The conclusion must be the LAST sentence(s) of the "answer" field, after any historical context.
- Separately, evaluate whether the retrieved sources actually CONFLICT with each other (not just differ over time in a clearly superseded way, but genuinely disagree on a fact that matters right now).

Respond with ONLY valid JSON in exactly this shape:
{{
  "answer": "your answer here, ending with the required conclusion sentence",
  "confidence": 85,
  "evidence": [
    {{"doc_name": "exact source name", "page": 4, "excerpt": "short relevant quote or paraphrase from that source"}}
  ],
  "is_conflicting": false,
  "conflict_details": {{
    "conflicting_sources": [],
    "description": "",
    "risk_level": "NONE"
  }}
}}

"confidence" is your own 0-100 estimate of how well the context supports the answer.
"is_conflicting" is true ONLY if sources genuinely disagree on something that currently matters (e.g. two policies both claim to be current but state different numbers). If one document is clearly superseded/outdated and the current one is unambiguous, set "is_conflicting" to false.
If "is_conflicting" is true, fill "conflict_details": list the exact conflicting document names in "conflicting_sources", a one-sentence "description" of what disagrees, and "risk_level" as one of "LOW", "MEDIUM", "HIGH" based on how consequential the disagreement is (e.g. compliance/legal/financial impact = HIGH).
If the context doesn't contain the answer, set "answer" to say so clearly (still ending with an "In conclusion..." sentence) and "confidence" to a low number like 10, with an empty "evidence" array and "is_conflicting" false."""

    model = genai.GenerativeModel(
        "gemini-3.6-flash",
        generation_config={"response_mime_type": "application/json"}
    )

    try:
        response = model.generate_content(prompt)
        parsed = json.loads(response.text)
    except Exception as e:
        return {
            "answer": f"AI service temporarily unavailable: {str(e)}",
            "confidence": 0,
            "evidence": [],
            "is_conflicting": False,
            "conflict_details": {"conflicting_sources": [], "description": "", "risk_level": "NONE"}
        }

    return parsed


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=False)





