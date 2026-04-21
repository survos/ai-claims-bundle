<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\AiClaimsBundle\Entity\ClaimRun;

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
