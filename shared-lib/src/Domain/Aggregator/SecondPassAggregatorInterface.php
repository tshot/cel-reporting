<?php

namespace CEL\Shared\Domain\Aggregator;

/**
 * SecondPassAggregatorInterface — DEPRECATED
 *
 * This interface is no longer needed. secondPass() has been promoted to
 * AggregatorInterface with a no-op default (Null Object pattern), which
 * eliminates all instanceof checks in callers.
 *
 * This file is kept only for backward compatibility. Any class that still
 * implements this interface will continue to compile because
 * AggregatorInterface already provides the method signature.
 *
 * @deprecated Implement AggregatorInterface and override secondPass() directly.
 *             Remove implements SecondPassAggregatorInterface from all classes.
 */
interface SecondPassAggregatorInterface extends AggregatorInterface
{
    // No new methods — secondPass() is now on AggregatorInterface.
    // This interface exists only to avoid breaking existing class declarations.
}
