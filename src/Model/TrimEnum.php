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
 * Whether the rows and columns of an original whose every pixel is fully transparent are cut before fitting.
 */
enum TrimEnum: string
{
    case None = 'none';
    case Clear = 'clear';
}
