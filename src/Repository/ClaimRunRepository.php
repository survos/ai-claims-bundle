<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\ClaimsBundle\Entity\ClaimRun;

/**
 * @extends ServiceEntityRepository<ClaimRun>
 */
final class ClaimRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClaimRun::class);
    }

    /**
     * Stream all runs for a scope, ordered by createdAt. Used for export.
     *
     * @return iterable<ClaimRun>
     */
    public function iterateForExport(?string $scope = null, ?string $subjectType = null): iterable
    {
        $qb = $this->createQueryBuilder('r')->orderBy('r.createdAt', 'ASC');

        if ($scope !== null) {
            $qb->andWhere('r.scope = :scope')->setParameter('scope', $scope);
        }
        if ($subjectType !== null) {
            $qb->andWhere('r.subjectType = :st')->setParameter('st', $subjectType);
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * The runs that produced the live claims for this (subject, source) pair.
     * Under append-only semantics this is normally just one row.
     *
     * @return list<ClaimRun>
     */
    public function findForSubjectAndSource(string $subjectType, string $subjectId, string $source, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('r.subjectId = :sid')->setParameter('sid', $subjectId)
            ->andWhere('r.source = :src')->setParameter('src', $source)
            ->orderBy('r.createdAt', 'DESC');

        if ($scope !== null) {
            $qb->andWhere('r.scope = :scope')->setParameter('scope', $scope);
        }

        /** @var list<ClaimRun> */
        return $qb->getQuery()->getResult();
    }
}
