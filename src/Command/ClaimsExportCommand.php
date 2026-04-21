<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Command;

use Survos\AiClaimsBundle\Entity\Claim;
use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Survos\JsonlBundle\IO\JsonlWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'claims:export',
    description: 'Export claims as JSONL to stdout or a file.',
)]
final class ClaimsExportCommand
{
    public function __construct(
        private readonly ClaimRepository $claims,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Restrict export to one scope.')]
        ?string $scope = null,
        #[Option('Restrict export to one subject type.')]
        ?string $subjectType = null,
        #[Option('Restrict export to one source.')]
        ?string $source = null,
        #[Option('Write to a .jsonl or .jsonl.gz file instead of stdout.')]
        ?string $output = null,
    ): int {
        if ($output !== null && !class_exists(JsonlWriter::class)) {
            $io->error("jsonl-bundle is not installed.\n\ncomposer req survos/jsonl-bundle");
            return Command::FAILURE;
        }

        $count = 0;
        $writer = $output !== null ? JsonlWriter::open($output) : null;
        $completed = false;

        try {
            foreach ($this->claims->iterateForExport($scope, $subjectType, $source) as $claim) {
                \assert($claim instanceof Claim);

                $row = $claim->toArray();

                if ($writer !== null) {
                    $writer->write($row);
                } else {
                    $json = \json_encode($row, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
                    if ($json === false) {
                        throw new \RuntimeException(sprintf('Failed to encode claim %s for export.', $row['id'] ?? '[unknown]'));
                    }

                    fwrite(\STDOUT, $json . "\n");
                }

                ++$count;
            }

            $completed = true;
        } finally {
            if ($writer !== null) {
                if ($completed) {
                    $writer->finish();
                } else {
                    $writer->close();
                }
            }
        }

        if ($output !== null) {
            $io->success(sprintf('Exported %d claim(s) to %s.', $count, $output));
        } else {
            $io->writeln(sprintf('Exported %d claim(s).', $count), \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        }

        return Command::SUCCESS;
    }
}
