<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

/**
 * Captures writes through one PHP stream resource without forwarding them.
 *
 * @internal
 */
final class CapturedStreamFilter extends \php_user_filter
{
    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        if (!$this->params instanceof OutputStreamBuffer) {
            return \PSFS_ERR_FATAL;
        }

        while (($bucket = \stream_bucket_make_writeable($in)) instanceof \StreamBucket) {
            $consumed += $bucket->datalen;
            $this->params->append($bucket->data);
        }

        return \PSFS_PASS_ON;
    }
}
