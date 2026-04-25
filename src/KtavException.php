<?php

declare(strict_types=1);

namespace Ktav;

/**
 * Thrown when the native library rejects an input — parse failure for
 * {@see Ktav::loads}, render failure for {@see Ktav::dumps}. The
 * message is the UTF-8 string returned by the native side.
 */
final class KtavException extends \RuntimeException
{
}
