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
 * What a profile's size means: the width, the height, the longest side, or the width of a crop to the profile's aspect.
 */
enum FitEnum: string
{
    case Width = 'width';
    case Height = 'height';
    case Contain = 'contain';
    case Cover = 'cover';
}
