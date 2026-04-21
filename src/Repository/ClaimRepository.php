<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\AiClaimsBundle\Entity\Claim;

/**
 * @extends ServiceEntityRepository<Claim>
 */
final class ClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Claim::class);
    }

    /**
     * All claims for one subject, optionally restricted to a scope.
     *
     * @return list<Claim>
     */
    public function findForSubject(string $subjectType, string $subjectId, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.subjectId = :sid')->setParameter('sid', $subjectId)
            ->orderBy('c.predicate', 'ASC')
            ->addOrderBy('c.createdAt', 'ASC');

        if ($scope !== null) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        /** @var list<Claim> */
        return $qb->getQuery()->getResult();
    }

    /**
     * All claims emitted by one tool for one subject. Used by the ingestor
     * to delete a prior run before writing a fresh one.
     *
     * @return list<Claim>
     */
    public function findForSubjectAndSource(string $subjectType, string $subjectId, string $source, ?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.subjectType = :st')->setParameter('st', $subjectType)
            ->andWhere('c.subjectId = :sid')->setParameter('sid', $subjectId)
            ->andWhere('c.source = :src')->setParameter('src', $source);

        if ($scope !== null) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        /** @var list<Claim> */
        return $qb->getQuery()->getResult();
    }

    /** @return list<Claim> */
    public function findByRun(string $runId): array
    {
        /** @var list<Claim> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.runId = :rid')->setParameter('rid', $runId)
            ->orderBy('c.predicate', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * @return iterable<Claim>
     */
    public function iterateForExport(?string $scope = null, ?string $subjectType = null, ?string $source = null): iterable
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC');

        if ($scope !== null) {
            $qb->andWhere('c.scope = :scope')->setParameter('scope', $scope);
        }

        if ($subjectType !== null) {
            $qb->andWhere('c.subjectType = :subjectType')->setParameter('subjectType', $subjectType);
        }

        if ($source !== null) {
            $qb->andWhere('c.source = :source')->setParameter('source', $source);
        }

        return $qb->getQuery()->toIterable();
    }
}
