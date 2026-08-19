<?php
namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DocumentController
{
    /* ---------- GET /api/documents — supports ?q= and ?limit= ---------- */
    public function index(Request $req, Response $res): Response
    {
        $params = $req->getQueryParams();
        $pdo = Connection::get();

        $sql = 'SELECT * FROM documents WHERE 1=1';
        $args = [];

        if (!empty($params['q'])) {
            $sql .= ' AND (name LIKE :q OR owner LIKE :q)';
            $args['q'] = '%' . $params['q'] . '%';
        }
        $sql .= ' ORDER BY upload_date DESC';
        if (!empty($params['limit'])) {
            $sql .= ' LIMIT ' . max(1, (int)$params['limit']);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $items = $stmt->fetchAll();

        return $this->json($res, ['count' => count($items), 'data' => $items]);
    }

    /* ---------- GET /api/documents/{id} ---------- */
    public function show(Request $req, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $doc = $this->findById($id);

        return $doc
            ? $this->json($res, $doc)
            : $this->json($res, ['error' => "Document {$id} not found"], 404);
    }

    /* ---------- POST /api/documents/upload — multipart file + metadata ---------- */

    public function create(Request $req, Response $res): Response
    {
        $uploadedFiles = $req->getUploadedFiles();
        $body = (array)($req->getParsedBody() ?? []);

        $errors = $this->validate($body, $uploadedFiles);
        if (!empty($errors)) return $this->json($res, ['errors' => $errors], 400);

        $file = $uploadedFiles['file'];
        $storedName = uniqid('doc_') . '_' . $file->getClientFilename();
        $dir = __DIR__ . '/../../storage/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $file->moveTo($dir . '/' . $storedName);

        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO documents
             (name, original_filename, storage_path, owner, category, version, approval_status)
             VALUES (:name, :orig, :path, :owner, :category, :version, :status)'
        );
        $stmt->execute([
            'name'     => trim($body['name'] ?? $file->getClientFilename()),
            'orig'     => $file->getClientFilename(),
            'path'     => 'storage/uploads/' . $storedName,
            'owner'    => trim($body['owner']),
            'category' => trim((string)($body['category'] ?? 'Uncategorised')),
            'version'  => trim((string)($body['version'] ?? '1.0')),
            'status'   => in_array($body['approval_status'] ?? '', ['Draft','Approved'])
                            ? $body['approval_status'] : 'Draft',
        ]);
        $id = (int)$pdo->lastInsertId();

        // Call the Python sidecar to extract page count
        $sidecarResponse = $this->callSidecar('storage/uploads/' . $storedName);
        error_log('SIDECAR RESPONSE: ' . var_export($sidecarResponse, true));
        if ($sidecarResponse !== null && isset($sidecarResponse['page_count'])) {
            $update = $pdo->prepare('UPDATE documents SET page_count = :pc WHERE id = :id');
            $update->execute(['pc' => $sidecarResponse['page_count'], 'id' => $id]);
        }

        $doc = $this->findById($id);
        return $this->json($res, ['message' => 'Document uploaded', 'data' => $doc], 201)
            ->withHeader('Location', '/api/documents/' . $id);
    }
    /* ---------- PUT /api/documents/{id} — metadata update, full or partial ---------- */
    public function update(Request $req, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $existing = $this->findById($id);
        if (!$existing) return $this->json($res, ['error' => "Document {$id} not found"], 404);

        $body = (array)($req->getParsedBody() ?? []);
        $fields = [];
        $bind = ['id' => $id];
        foreach (['name','owner','category','version','approval_status','page_count'] as $k) {
            if (array_key_exists($k, $body)) {
                $fields[] = "$k = :$k";
                $bind[$k] = $body[$k];
            }
        }
        if (empty($fields)) return $this->json($res, ['error' => 'No updatable fields provided'], 400);

        $pdo = Connection::get();
        $stmt = $pdo->prepare('UPDATE documents SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($bind);

        return $this->json($res, ['message' => 'Document updated', 'data' => $this->findById($id)]);
    }

    /* ---------- DELETE /api/documents/{id} ---------- */
    public function delete(Request $req, Response $res, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $existing = $this->findById($id);
        if (!$existing) return $this->json($res, ['error' => "Document {$id} not found"], 404);

        $pdo = Connection::get();
        $pdo->prepare('DELETE FROM documents WHERE id = :id')->execute(['id' => $id]);

        return $this->json($res, ['message' => 'Document deleted', 'data' => $existing]);
    }

    /* ---------- helpers ---------- */
    private function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function validate(array $body, array $files): array
    {
        $errors = [];
        if (empty($files['file']) || $files['file']->getError() !== UPLOAD_ERR_OK) {
            $errors['file'] = 'A PDF file is required';
        } elseif ($files['file']->getClientMediaType() !== 'application/pdf') {
            $errors['file'] = 'Only PDF files are accepted';
        }
        if (empty($body['owner'])) $errors['owner'] = 'owner is required';
        return $errors;
    }

    private function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withStatus($status);
    }
    private function callSidecar(string $storagePath): ?array
{
    $ch = curl_init('http://localhost:5000/process-pdf');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['storage_path' => $storagePath]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        // Don't fail the whole upload if the sidecar is down —
        // the document is still saved, just without page_count for now.
        return null;
    }
    return json_decode($response, true);
}


}