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
 * Whether renditions are made in the call that stores the original, or by dispatching MakeRenditions.
 */
enum GenerateEnum: string
{
    case Sync = 'sync';
    case Async = 'async';
}
