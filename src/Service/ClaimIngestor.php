<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Survos\AiClaimsBundle\Entity\Claim;
use Survos\AiClaimsBundle\Entity\ClaimRun;
use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Survos\AiClaimsBundle\Repository\ClaimRunRepository;
use Symfony\Component\Uid\Ulid;

/**
 * Writes a batch of ClaimDrafts as rows for a given subject and source, and
 * records a sibling ClaimRun audit row with whatever call metadata the caller
 * has on hand (prompt text, model, tokens, duration).
 *
 * Rerun semantics: on record(), all existing claims AND the prior ClaimRun
 * for (scope, subjectType, subjectId, source) are removed first, then fresh
 * ones are persisted sharing one runId. The caller decides when to flush.
 */
final class ClaimIngestor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaimRepository $claims,
        private readonly ClaimRunRepository $runs,
    ) {}

    /**
     * @param list<ClaimDraft> $drafts
     * @return string The runId shared by every persisted Claim + its ClaimRun row.
     */
    public function record(
        ?string $scope,
        string $subjectType,
        string $subjectId,
        string $source,
        array $drafts,
        ?RunMeta $meta = null,
        ?string $runId = null,
    ): string {
        $runId ??= (string) new Ulid();

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
            claimCount:   count($drafts),
            id:           $runId,
        );
        $this->em->persist($run);

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
