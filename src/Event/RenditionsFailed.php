<?php

declare(strict_types=1);

/*
 * This file is part of the UtafitiLabs Image Bundle.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace UtafitiLabs\ImageBundle\Event;

use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;

/**
 * The renditions of a stored original could not be made: its original is missing (deleted before the worker reached
 * it, for one) or cannot be read as a picture.
 */
final readonly class RenditionsFailed
{
    public function __construct(
        public string $profile,
        public string $name,
        public FailureReasonEnum $reason,
    ) {
    }
}
