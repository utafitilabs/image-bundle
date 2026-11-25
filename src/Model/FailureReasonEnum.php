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
 * Why renditions could not be made from an original.
 */
enum FailureReasonEnum: string
{
    case Missing = 'missing';
    case Unreadable = 'unreadable';
}
