<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\ClaimsBundle\Entity\Claim;
use Survos\ClaimsBundle\Entity\ClaimRun;
use Survos\ClaimsBundle\Repository\ClaimRepository;
use Survos\ClaimsBundle\Repository\ClaimRunRepository;
use Survos\DatasetBundle\Service\DataPaths;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\JsonlBundle\IO\JsonlWriterOptions;
use Symfony\Component\Uid\Ulid;

/**
 * Writes a batch of RawClaims for a given subject and source.
 *
 * JSONL-first: the scope's `claims.jsonl` (in the vault) is the durable authority for
 * every claim that flows through the ai-workflow. The Doctrine Claim/ClaimRun rows are
 * a rebuildable index of the entity under examination — convenient for queries, but not
 * the source of truth. A null scope (ad-hoc / one-shot subject) skips the log.
 *
 * Rerun semantics: on record(), all existing DB claims AND the prior ClaimRun for
 * (scope, subjectType, subjectId, source) are removed first, then fresh ones are
 * persisted sharing one runId. claims.jsonl is append-only; supersession is resolved
 * by the sidecar index / reindex (latest wins per subject+predicate+source). The caller
 * decides when to flush the DB.
 */
final class ClaimIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimRepository $claims,
        private readonly ClaimRunRepository $runs,
        private readonly ?DataPaths $dataPaths = null,
    ) {
    }

    /**
     * @param list<RawClaim> $rawClaims
     */
    public function record(
        ?string $scope,
        string $subjectType,
        string $subjectId,
        string $source,
        array $rawClaims,
        ?RunMeta $meta = null,
        ?string $runId = null,
    ): ClaimRun {
        $runId ??= (string) new Ulid();

        // ── JSONL authority: append to the scope's claims.jsonl in the vault ──────
        $this->appendToClaimsJsonl($scope, $subjectType, $subjectId, $source, $rawClaims, $runId);

        // ── DB index (rebuildable): delete-then-insert for this subject+source ────
        foreach ($this->claims->findForSubjectAndSource($subjectType, $subjectId, $source, $scope) as $stale) {
            $this->em->remove($stale);
        }
        foreach ($this->runs->findForSubjectAndSource($subjectType, $subjectId, $source, $scope) as $staleRun) {
            $this->em->remove($staleRun);
        }

        $run = new ClaimRun(
            scope:        $scope,
            subjectType:  $subjectType,
            subjectId:    $subjectId,
            source:       $source,
            model:        $meta?->model,
            prompt:       $meta?->prompt,
            response:     $meta?->response,
            inputTokens:  $meta?->inputTokens,
            outputTokens: $meta?->outputTokens,
            imageTokens:  $meta?->imageTokens,
            durationMs:   $meta?->durationMs,
            claimCount:   count($rawClaims),
            id:           $runId,
        );
        $this->em->persist($run);

        foreach ($rawClaims as $rawClaim) {
            $claim = new Claim(
                scope:       $scope,
                subjectType: $subjectType,
                subjectId:   $subjectId,
                predicate:   $rawClaim->predicate,
                source:      $source,
                value:       $rawClaim->value,
                confidence:  $rawClaim->confidence,
                basis:       $rawClaim->basis,
                runId:       $runId,
            );
            $this->em->persist($claim);
        }

        return $run;
    }

    /**
     * @param list<RawClaim> $rawClaims
     */
    private function appendToClaimsJsonl(?string $scope, string $subjectType, string $subjectId, string $source, array $rawClaims, string $runId): void
    {
        if (null === $this->dataPaths || null === $scope || '' === $scope || [] === $rawClaims) {
            return;
        }

        $createdAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $writer = JsonlWriter::open($this->dataPaths->claimsFile($scope), 'a', JsonlWriterOptions::noLock());
        foreach ($rawClaims as $rawClaim) {
            $writer->write([
                'scope' => $scope,
                'subjectType' => $subjectType,
                'subjectId' => $subjectId,
                'predicate' => $rawClaim->predicate,
                'source' => $source,
                'value' => $rawClaim->value,
                'confidence' => $rawClaim->confidence,
                'basis' => $rawClaim->basis,
                'runId' => $runId,
                'createdAt' => $createdAt,
            ]);
        }
        $writer->finish(markComplete: true);
    }
}
