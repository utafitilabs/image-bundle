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

namespace UtafitiLabs\ImageBundle\Exception;

use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;

final class UnreadableImageException extends \RuntimeException implements ImageExceptionInterface
{
    public function __construct(
        public readonly FailureReasonEnum $reason,
        string $what,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(FailureReasonEnum::Missing === $reason ? \sprintf('The original %s is missing.', $what) : \sprintf('%s cannot be read as an image.', $what), 0, $previous);
    }
}
