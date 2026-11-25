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

/**
 * Every rendition of a stored original is in place; the paths are those written this time, in the renditions
 * storage, and are empty when nothing was missing.
 */
final readonly class RenditionsWritten
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public string $profile,
        public string $name,
        public array $paths,
    ) {
    }
}
