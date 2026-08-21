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

    $answer          = $sidecarResponse['answer']          ?? 'No answer returned';
    $confidence      = $sidecarResponse['confidence']      ?? 0;
    $evidence        = $sidecarResponse['evidence']        ?? [];
    $isConflicting   = $sidecarResponse['is_conflicting']  ?? false;
    $conflictDetails = $sidecarResponse['conflict_details'] ?? [];
    $completenessRaw = $sidecarResponse['completeness']    ?? 0;
    $completeness    = round($completenessRaw * 100, 1);

    $pdo = Connection::get();

    // --- Log chat + citations + conflicts (unchanged from C4/C5) ---
    $stmt = $pdo->prepare('INSERT INTO chat_logs (query, response, sources) VALUES (:q, :r, :s)');
    $stmt->execute(['q' => $query, 'r' => $answer, 's' => json_encode($evidence)]);
    $chatLogId = (int)$pdo->lastInsertId();

    if (!empty($evidence)) {
        $citeStmt = $pdo->prepare(
            'INSERT INTO citations (chat_log_id, source_doc, page_num, excerpt) VALUES (:cid, :doc, :page, :excerpt)'
        );
        foreach ($evidence as $item) {
            $citeStmt->execute([
                'cid' => $chatLogId, 'doc' => $item['doc_name'] ?? 'Unknown',
                'page' => $item['page'] ?? 0, 'excerpt' => $item['excerpt'] ?? '',
            ]);
        }
    }

    if ($isConflicting && !empty($conflictDetails['conflicting_sources'])) {
        $sources = $conflictDetails['conflicting_sources'];
        $docId = count($sources) >= 1 ? $this->findDocIdByName($sources[0]) : null;
        $conflictingDocId = count($sources) >= 2 ? $this->findDocIdByName($sources[1]) : null;

        $conflictStmt = $pdo->prepare(
            'INSERT INTO detected_conflicts (document_id, conflicting_doc_id, description, risk_level)
             VALUES (:doc, :cdoc, :desc, :risk)'
        );
        $conflictStmt->execute([
            'doc' => $docId, 'cdoc' => $conflictingDocId,
            'desc' => $conflictDetails['description'] ?? '',
            'risk' => $conflictDetails['risk_level'] ?? 'LOW',
        ]);
    }

    // --- NEW: Component 6 — Integrity Score calculation ---

    // Consistency Score: based on conflict detection
    $riskLevel = strtoupper($conflictDetails['risk_level'] ?? 'NONE');
    $consistencyScore = match (true) {
        !$isConflicting => 100,
        $riskLevel === 'HIGH' => 0,
        $riskLevel === 'MEDIUM' => 30,
        default => 70, // LOW risk / minor variance
    };

    // Freshness + Approval: averaged over the documents referenced in evidence
    $freshnessScores = [];
    $approvalScores = [];
    foreach ($evidence as $item) {
        $doc = $this->findDocByName($item['doc_name'] ?? '');
        if (!$doc) continue;

        $daysSince = (strtotime('now') - strtotime($doc['upload_date'])) / 86400;
        $freshnessScores[] = max(0, 100 - ($daysSince * 0.1));

        $approvalScores[] = ($doc['approval_status'] === 'Approved') ? 100 : 50;
    }
    $freshnessScore = !empty($freshnessScores) ? round(array_sum($freshnessScores) / count($freshnessScores), 1) : 50;
    $approvalScore  = !empty($approvalScores) ? round(array_sum($approvalScores) / count($approvalScores), 1) : 50;

    // Overall Weighted KII Formula (per spec)
    $overallScore = round(
        ($consistencyScore * 0.35) +
        ($confidence * 0.20) +
        ($completeness * 0.15) +
        ($freshnessScore * 0.15) +
        ($approvalScore * 0.15),
        1
    );

    // Store the full score breakdown
    $scoreStmt = $pdo->prepare(
        'INSERT INTO integrity_scores
         (chat_log_id, confidence, freshness, consistency, completeness, approval, overall)
         VALUES (:cid, :conf, :fresh, :cons, :comp, :appr, :overall)'
    );
    $scoreStmt->execute([
        'cid' => $chatLogId, 'conf' => $confidence, 'fresh' => $freshnessScore,
        'cons' => $consistencyScore, 'comp' => $completeness, 'appr' => $approvalScore,
        'overall' => $overallScore,
    ]);

    return $this->json($res, [
        'answer' => $answer,
        'evidence' => $evidence,
        'is_conflicting' => $isConflicting,
        'conflict_details' => $conflictDetails,
        'integrity_score' => [
            'confidence' => $confidence,
            'freshness' => $freshnessScore,
            'consistency' => $consistencyScore,
            'completeness' => $completeness,
            'approval' => $approvalScore,
            'overall' => $overallScore,
        ],
    ]);
}

private function findDocIdByName(string $name): ?int
{
    $stmt = Connection::get()->prepare('SELECT id FROM documents WHERE name LIKE :name LIMIT 1');
    $stmt->execute(['name' => '%' . $name . '%']);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

private function findDocByName(string $name): ?array
{
    if ($name === '') return null;
    $stmt = Connection::get()->prepare('SELECT * FROM documents WHERE name LIKE :name LIMIT 1');
    $stmt->execute(['name' => '%' . $name . '%']);
    $row = $stmt->fetch();
    return $row ?: null;
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