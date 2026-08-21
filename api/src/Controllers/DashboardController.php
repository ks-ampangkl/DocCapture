<?php
namespace App\Controllers;

use App\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DashboardController
{
    public function index(Request $req, Response $res): Response
    {
        $pdo = Connection::get();

        // Total documents
        $totalDocs = (int)$pdo->query('SELECT COUNT(*) FROM documents')->fetchColumn();

        // Conflicts (all-time, from detected_conflicts table)
        $conflictCount = (int)$pdo->query('SELECT COUNT(*) FROM detected_conflicts')->fetchColumn();

        // Outdated documents — freshness under a threshold, e.g. score < 50
        // (i.e. uploaded more than ~500 days ago per the 0.1/day decay formula, OR just use a simpler days-based cutoff for clarity)
        $outdatedStmt = $pdo->query(
            'SELECT COUNT(*) FROM documents WHERE DATEDIFF(NOW(), upload_date) > 180'
        );
        $outdatedCount = (int)$outdatedStmt->fetchColumn();

        // Missing documentation — placeholder heuristic: docs with no page_count captured
        $missingDocsStmt = $pdo->query(
            'SELECT COUNT(*) FROM documents WHERE page_count IS NULL OR page_count = 0'
        );
        $missingDocsCount = (int)$missingDocsStmt->fetchColumn();

        // High risk — from detected_conflicts where risk_level = HIGH
        $highRiskStmt = $pdo->query(
            "SELECT COUNT(DISTINCT document_id) FROM detected_conflicts WHERE risk_level = 'HIGH'"
        );
        $highRiskCount = (int)$highRiskStmt->fetchColumn();

        // Overall Integrity — average of all logged integrity_scores.overall
        $overallStmt = $pdo->query('SELECT AVG(overall) FROM integrity_scores');
        $overallAvg = $overallStmt->fetchColumn();
        $overallIntegrity = $overallAvg !== null ? round((float)$overallAvg, 1) : 0;

        // Recent conflicts list (for the dashboard's "Conflicts" panel)
        $recentConflicts = $pdo->query(
            'SELECT dc.*, d1.name AS document_name, d2.name AS conflicting_document_name
             FROM detected_conflicts dc
             LEFT JOIN documents d1 ON dc.document_id = d1.id
             LEFT JOIN documents d2 ON dc.conflicting_doc_id = d2.id
             ORDER BY dc.created_at DESC LIMIT 10'
        )->fetchAll();

        // High-risk documents list
        $highRiskDocs = $pdo->query(
            "SELECT DISTINCT d.id, d.name
             FROM detected_conflicts dc
             JOIN documents d ON dc.document_id = d.id
             WHERE dc.risk_level = 'HIGH'
             LIMIT 10"
        )->fetchAll();

        // Outdated documents list
        $outdatedDocs = $pdo->query(
            'SELECT id, name, upload_date FROM documents
             WHERE DATEDIFF(NOW(), upload_date) > 180
             ORDER BY upload_date ASC LIMIT 10'
        )->fetchAll();

        return $this->json($res, [
            'summary' => [
                'total_documents'   => $totalDocs,
                'overall_integrity' => $overallIntegrity,
                'conflicts'         => $conflictCount,
                'outdated'          => $outdatedCount,
                'missing_docs'      => $missingDocsCount,
                'high_risk'         => $highRiskCount,
            ],
            'recent_conflicts' => $recentConflicts,
            'high_risk_docs'   => $highRiskDocs,
            'outdated_docs'    => $outdatedDocs,
        ]);
    }

    private function json(Response $res, mixed $data, int $status = 200): Response
    {
        $res->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8')
                    ->withStatus($status);
    }
}