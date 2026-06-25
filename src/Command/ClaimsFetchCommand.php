<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Command;

use Survos\ClaimsBundle\Service\ClaimReader;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reader-side counterpart to `claims:export` (which is writer/ORM-only): pull a single dataset's
 * claims from mediary's central store (via the read-only {@see ClaimReader}, filtered by scope)
 * into the vault claims.jsonl. This is the "fetch" step of the pipeline:
 *
 *   claims:fetch <dataset>  →  dataset:enrich  →  folio:build
 *
 * Materialising claims to the vault once means `dataset:enrich` folds them from a local file
 * instead of querying the live DB per row — faster, and resilient to the claims DB being
 * unreachable at build time.
 */
#[AsCommand('claims:fetch', 'Fetch a dataset\'s claims from mediary into the vault claims.jsonl (so enrich reads locally, never the live DB).')]
final class ClaimsFetchCommand
{
    public function __construct(
        private readonly ClaimReader $reader,
        private readonly ?DataPaths $dataPaths = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset key / claim scope, e.g. mus/fortepan')]
        string $dataset,
        #[Option('Write to this path instead of the vault default (DataPaths::claimsFile).')]
        ?string $output = null,
    ): int {
        if (!$this->reader->isAvailable()) {
            $io->error("No claims connection. Set MEDIARY_RO_DATABASE_URL so the read-only `mediary_ro` connection is registered.");
            return Command::FAILURE;
        }
        if (!class_exists(JsonlWriter::class)) {
            $io->error("jsonl-bundle is not installed.\n\ncomposer req survos/jsonl-bundle");
            return Command::FAILURE;
        }

        $output ??= $this->dataPaths?->claimsFile($dataset);
        if ($output === null) {
            $io->error('No output path: dataset-bundle (DataPaths) is unavailable, so pass --output explicitly.');
            return Command::FAILURE;
        }

        $rows = $this->reader->forScope($dataset);

        $count = 0;
        $writer = JsonlWriter::open($output);
        $completed = false;
        try {
            foreach ($rows as $row) {
                // DBAL returns snake_case columns; write the canonical claim shape the vault uses.
                $writer->write([
                    'scope'       => $row['scope'],
                    'subjectType' => $row['subject_type'],
                    'subjectId'   => $row['subject_id'],
                    'predicate'   => $row['predicate'],
                    'source'      => $row['source'],
                    'value'       => $row['value'],
                    'confidence'  => $row['confidence'],
                    'basis'       => $row['basis'],
                    'runId'       => $row['run_id'],
                    'createdAt'   => $row['created_at'],
                ]);
                ++$count;
            }
            $completed = true;
        } finally {
            $completed ? $writer->finish() : $writer->close();
        }

        $io->success(sprintf('Fetched %d claim(s) for "%s" → %s', $count, $dataset, $output));

        return Command::SUCCESS;
    }
}
