<?php

declare(strict_types=1);

namespace Survos\ClaimsBundle\Exception;

/**
 * The claims store could not be read — as distinct from "there are no claims".
 *
 * Those two things are the same value (`[]`) unless something says otherwise, and conflating them
 * is how a transport failure turns into data loss: {@see \Survos\ClaimsBundle\Service\ClaimsVaultWriter}
 * materialises claims.jsonl, which enrich then reads as truth, so an unreadable store silently
 * became "this dataset has no claims" and the folio was rebuilt without them.
 *
 * Consumers that merely DISPLAY claims should catch this and degrade — mediary being briefly
 * unreachable should cost a panel, not a page. Consumers that PERSIST what they read must not
 * catch it, because a write derived from a failed read is worse than no write at all.
 */
final class ClaimsUnavailableException extends \RuntimeException
{
}
