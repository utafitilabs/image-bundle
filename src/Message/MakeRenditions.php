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

namespace UtafitiLabs\ImageBundle\Message;

/**
 * Make the missing renditions of a stored original; dispatched by a profile with "generate: async". The application
 * routes it to a transport.
 */
final readonly class MakeRenditions
{
    public function __construct(
        public string $profile,
        public string $name,
    ) {
    }
}
