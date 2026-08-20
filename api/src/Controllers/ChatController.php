<?php
namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ChatController
{
    public function submit(Request $req, Response $res): Response
    {
        $body = (array)($req->getParsedBody() ?? []);
        $query = trim((string)($body['query'] ?? ''));

        if ($query === '') {
            return $this->json($res, ['error' => 'Query is required'], 400);
        }

        $sidecarResponse = $this->callSidecar('/rag-query', ['query' => $query]);

        if ($sidecarResponse === null) {
            return $this->json($res, ['error' => 'Sidecar unreachable'], 502);
        }

        $answer  = $sidecarResponse['answer']  ?? 'No answer returned';
        $confidence = $sidecarResponse['confidence'] ?? 0;
        $evidence   = $sidecarResponse['evidence']   ?? [];

        $pdo = Connection::get();
        $stmt = $pdo->prepare(
            'INSERT INTO chat_logs (query, response, sources) VALUES (:q, :r, :s)'
        );
        $stmt->execute([
            'q' => $query,
            'r' => $answer,
            's' => json_encode($evidence),
        ]);

        $chatLogId = (int)$pdo->lastInsertId();

    if (!empty($evidence)) {
        $citeStmt = $pdo->prepare(
            'INSERT INTO citations (chat_log_id, source_doc, page_num, excerpt) VALUES (:cid, :doc, :page, :excerpt)'
        );
        foreach ($evidence as $item) {
            $citeStmt->execute([
                'cid'     => $chatLogId,
                'doc'     => $item['doc_name'] ?? 'Unknown',
                'page'    => $item['page'] ?? 0,
                'excerpt' => $item['excerpt'] ?? '',
            ]);
        }
    }

    return $this->json($res, [
        'answer' => $answer,
        'confidence' => $confidence,
        'evidence' => $evidence,
    ]);
    }

    private function callSidecar(string $endpoint, array $payload): ?array
    {
        $ch = curl_init('http://localhost:5000' . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) return null;
        return json_decode($response, true);
    }

    private function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withStatus($status);
    }
}