<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * Ends a verification as "malformed". Thrown and caught inside ReceiptVerifier::verify(), never
 * seen by a caller.
 *
 * @internal
 */
final class MalformedInput extends \RuntimeException
{
}
