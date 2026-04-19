<?php

declare(strict_types=1);

namespace Survos\AiClaimsBundle\Service;

/**
 * Immutable intermediate DTO — a claim before it hits the DB.
 * Tools assemble a list of these and hand them to ClaimIngestor.
 */
final class ClaimDraft
{
    public function __construct(
        public readonly string  $predicate,
        public readonly mixed   $value,
        public readonly float   $confidence = 1.0,
        public readonly ?string $basis      = null,
    ) {}
}
