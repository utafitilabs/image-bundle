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

namespace UtafitiLabs\ImageBundle\Model;

/**
 * Why an upload was refused, and the profile's limit it broke when there is one (bytes, pixels or a side in pixels).
 */
final readonly class Refusal
{
    public function __construct(
        public RefusalReasonEnum $reason,
        public ?int $limit = null,
    ) {
    }
}
