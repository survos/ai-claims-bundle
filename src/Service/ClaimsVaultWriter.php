<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Survos\DatasetBundle\Service\DataPaths;
use Survos\JsonlBundle\IO\JsonlWriter;

/**
 * Materialise a dataset's claims (and runs) from the central claims DB into the vault JSONL — the
 * read-side "fetch" of the pipeline ({@see ClaimReader} → vault `claims.jsonl`/`claim-runs.jsonl`).
 *
 * Single owner of the vault row shape: both `claims:fetch` and `dataset:assemble`'s inline
 * pre-enrich fetch call this, so the shape never drifts between them.
 */
final class ClaimsVaultWriter
{
    public function __construct(
        private readonly ClaimReader $reader,
        // Optional: dataset-bundle owns the vault layout. Without it, callers must pass $output.
        private readonly ?DataPaths $dataPaths = null,
    ) {
    }

    /** True when the claims DB connection is wired (delegates to {@see ClaimReader::isAvailable()}). */
    public function isAvailable(): bool
    {
        return $this->reader->isAvailable();
    }

    /**
     * Write <scope>'s claims to $output (default: the vault claims.jsonl) and its runs to the sibling
     * claim-runs.jsonl. Runs are best-effort — an older claims DB may lack the claim_run table, so a
     * runs failure leaves runs=0 but never fails the claims write.
     *
     * @return array{claims:int, runs:int, output:string, runsOutput:?string}
     */
    public function write(string $scope, ?string $output = null): array
    {
        $output ??= $this->dataPaths?->claimsFile($scope);
        if ($output === null) {
            throw new \RuntimeException('No output path: dataset-bundle (DataPaths) is unavailable, so pass $output explicitly.');
        }

        $count = 0;
        $writer = JsonlWriter::open($output);
        $completed = false;
        try {
            foreach ($this->reader->forScope($scope) as $row) {
                // DBAL returns snake_case columns; write the canonical claim shape the vault uses.
                $writer->write([
                    'scope' => $row['scope'],
                    'subjectType' => $row['subject_type'],
                    'subjectId' => $row['subject_id'],
                    'predicate' => $row['predicate'],
                    'source' => $row['source'],
                    'value' => $row['value'],
                    'confidence' => $row['confidence'],
                    'basis' => $row['basis'],
                    'runId' => $row['run_id'],
                    'createdAt' => $row['created_at'],
                ]);
                ++$count;
            }
            $completed = true;
        } finally {
            $completed ? $writer->finish() : $writer->close();
        }

        $runCount = 0;
        $runsOutput = $this->dataPaths?->claimsFile($scope, 'claim-runs.jsonl');
        if ($runsOutput !== null) {
            try {
                $rw = JsonlWriter::open($runsOutput);
                $ok = false;
                try {
                    foreach ($this->reader->runsForScope($scope) as $r) {
                        $rw->write([
                            'id' => $r['id'],
                            'scope' => $r['scope'],
                            'subjectType' => $r['subject_type'],
                            'subjectId' => $r['subject_id'],
                            'source' => $r['source'],
                            'model' => $r['model'],
                            'prompt' => $r['prompt'],
                            'response' => $r['response'],
                            'inputTokens' => $r['input_tokens'],
                            'outputTokens' => $r['output_tokens'],
                            'imageTokens' => $r['image_tokens'],
                            'durationMs' => $r['duration_ms'],
                            'claimCount' => $r['claim_count'],
                            'createdAt' => $r['created_at'],
                        ]);
                        ++$runCount;
                    }
                    $ok = true;
                } finally {
                    $ok ? $rw->finish() : $rw->close();
                }
            } catch (\Throwable) {
                $runCount = 0; // older claims DB without claim_run — claims still written
                $runsOutput = null;
            }
        }

        return ['claims' => $count, 'runs' => $runCount, 'output' => $output, 'runsOutput' => $runsOutput];
    }
}
