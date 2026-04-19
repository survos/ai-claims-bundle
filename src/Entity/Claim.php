<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\AiClaimsBundle\Repository\ClaimRepository;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Ulid;

/**
 * A single AI (or human) assertion about a subject — the uniform envelope
 * for every predicate a tool emits. Claims are append-only: rerunning a tool
 * deletes the prior run's rows for that (scope, subject, source) and writes
 * fresh ones.
 *
 * Predicate is a compact IRI-ish string chosen by the consumer
 * ('dcterms:title', 'ssai:speculation', 'foaf:Person', ...). The bundle
 * ships no predicate vocabulary — that's an app concern.
 *
 * Scope is an app-defined string that partitions claims for tenancy / user
 * isolation / globality. Examples: 'tenant:rhs', 'user:42', 'global', null.
 * The bundle never interprets it; indexes include it so the app can enforce
 * scope-isolation at query time.
 */
#[ORM\Entity(repositoryClass: ClaimRepository::class)]
#[ORM\Table(name: 'claim')]
#[ORM\Index(fields: ['scope', 'subjectType', 'subjectId', 'predicate'], name: 'idx_claim_scope_subject_pred')]
#[ORM\Index(fields: ['scope', 'source'],                                 name: 'idx_claim_scope_source')]
#[ORM\Index(fields: ['runId'],                                           name: 'idx_claim_run')]
#[ApiResource(
    operations: [
        new Get(uriTemplate: '/claims/{id}'),
        new GetCollection(uriTemplate: '/claims'),
    ],
    normalizationContext: ['groups' => ['claim:read']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'scope'       => 'exact',
    'subjectType' => 'exact',
    'subjectId'   => 'exact',
    'predicate'   => 'exact',
    'source'      => 'partial',
])]
#[ApiFilter(RangeFilter::class, properties: ['confidence'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'confidence', 'predicate', 'source'])]
class Claim
{
    #[ORM\Id]
    #[ORM\Column(length: 26)]
    #[Groups(['claim:read'])]
    #[ApiProperty(
        identifier: true,
        description: 'ULID — stable, lexicographically time-sortable identifier.',
        example: '01JH4M8PGQ7X2WC3K5D9B8T2VS',
    )]
    public private(set) string $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['claim:read'])]
    #[ApiProperty(
        description: 'When this claim row was recorded (tool invocation time, not subject creation time).',
        example: '2026-04-19T14:32:08+00:00',
    )]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct(
        /** App-defined partition key. Examples: 'tenant:rhs', 'user:42', 'global', null. */
        #[ORM\Column(length: 64, nullable: true)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'App-defined partition key for isolating claims by tenant, user, or none. Bundle treats it opaquely; the consumer enforces access control.',
            example: 'tenant:rhs',
        )]
        public private(set) ?string $scope,

        /** App-defined subject class key. Examples: 'image', 'item', 'document'. */
        #[ORM\Column(length: 32)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Class of the subject this claim is about. App-defined; no FK is enforced by the bundle.',
            example: 'image',
        )]
        public private(set) string $subjectType,

        /** ULID / UUID / hash identifying the specific subject. */
        #[ORM\Column(length: 64)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Identifier of the subject within its type. Typically a ULID or hash.',
            example: '01J3KXYZ1234567890ABCDEFGH',
        )]
        public private(set) string $subjectId,

        /** Compact IRI: 'dcterms:title', 'ssai:speculation', 'foaf:Person', ... */
        #[ORM\Column(length: 64)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Compact IRI for the assertion type. Consumers pick their vocabulary (DcTerms recommended for standard fields; app-namespaced for extensions).',
            example: 'dcterms:title',
        )]
        public private(set) string $predicate,

        /**
         * Stable tool identifier with version.
         * Examples: 'enrich_from_thumbnail@1.0', 'ocr:tesseract@5',
         * 'human:tac@gmail.com', 'fixture', 'aggregated'.
         */
        #[ORM\Column(length: 128)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Stable tool identifier with version. Humans use "human:{email}"; fixtures use "fixture"; aggregator output uses "aggregated".',
            example: 'enrich_from_thumbnail@1.0',
        )]
        public private(set) string $source,

        /**
         * The asserted value. Scalar for most predicates; structured for
         * places (with coords), speculations (with internal fields), etc.
         */
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Asserted value. Scalar for most predicates (string, bool, number); object for structured predicates like places with coordinates.',
            example: 'Welcome to Ocean City',
        )]
        public private(set) mixed $value = null,

        /** 0.0–1.0. Tool layer maps enum (high/medium/low) to floats here. */
        #[ORM\Column(type: Types::FLOAT)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Confidence 0.0–1.0. Mapping convention: high=0.9, medium=0.6, low=0.3. Humans typically assert 1.0.',
            example: 0.9,
        )]
        public private(set) float $confidence = 1.0,

        /** Why the tool asserted this (prompt reasoning, visible evidence, …). */
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Why the tool asserted this — visible evidence, prompt reasoning, or "operator verified" for humans. Critical for hallucination review.',
            example: "Printed caption on front of postcard reads 'Welcome to Ocean City'.",
        )]
        public private(set) ?string $basis = null,

        /**
         * Groups every claim produced by one tool invocation.
         * Null for human edits or fixtures that don't simulate a run.
         */
        #[ORM\Column(length: 26, nullable: true)]
        #[Groups(['claim:read'])]
        #[ApiProperty(
            description: 'Groups all claims emitted by one tool invocation. Used for rerun semantics (delete prior run, insert fresh) and for audit trails.',
            example: '01JH4M8PGQ7X2WC3K5D9B8T2VS',
        )]
        public private(set) ?string $runId = null,
    ) {
        $this->id        = (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }
}
