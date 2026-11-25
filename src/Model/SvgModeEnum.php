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
 * Whether a profile refuses SVG or accepts it and writes one cleaned copy for every size.
 */
enum SvgModeEnum: string
{
    case Refuse = 'refuse';
    case Sanitise = 'sanitise';
}
