<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\AiClaimsBundle\Entity\Claim;
use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Writes a batch of ClaimDrafts as rows for a given subject and source.
 *
 * Rerun semantics: on record(), all existing rows for
 * (scope, subjectType, subjectId, source) are deleted first, then the new
 * drafts are persisted with a shared runId. The caller decides when to flush.
 */
final class ClaimIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimRepository $claims,
    ) {}

    /**
     * @param list<ClaimDraft> $drafts
     * @return string The runId shared by every persisted Claim.
     */
    public function record(
        ?string $scope,
        string $subjectType,
        string $subjectId,
        string $source,
        array $drafts,
        ?string $runId = null,
    ): string {
        $runId ??= (string) new Ulid();

        foreach ($this->claims->findForSubjectAndSource($subjectType, $subjectId, $source, $scope) as $stale) {
            $this->em->remove($stale);
        }

        foreach ($drafts as $draft) {
            $claim = new Claim(
                scope:       $scope,
                subjectType: $subjectType,
                subjectId:   $subjectId,
                predicate:   $draft->predicate,
                source:      $source,
                value:       $draft->value,
                confidence:  $draft->confidence,
                basis:       $draft->basis,
                runId:       $runId,
            );
            $this->em->persist($claim);
        }

        return $runId;
    }
}
