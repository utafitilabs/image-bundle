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

namespace UtafitiLabs\ImageBundle\Tests\Functional;

use UtafitiLabs\ImageBundle\Service\ImageCheckerService;
use UtafitiLabs\ImageBundle\Service\ImagesInterface;

/**
 * An application service that type-hints what the bundle aliases.
 */
final readonly class ImageServicesConsumer
{
    public function __construct(
        public ImagesInterface $images,
        public ImageCheckerService $checker,
    ) {
    }
}
