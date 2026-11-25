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
 * Why an upload may not be stored under a profile; each value is the profile key whose limit it broke, or the kind
 * of problem. The application maps it to its own words.
 */
enum RefusalReasonEnum: string
{
    /** The upload did not arrive whole (PHP's upload error other than a size limit). */
    case NotArrived = 'not_arrived';
    /** Its content is not a type the profile accepts. */
    case Type = 'type';
    /** It claims an accepted type but cannot be read as one. */
    case Unreadable = 'unreadable';
    case Bytes = 'bytes';
    case Pixels = 'pixels';
    case MinWidth = 'min_width';
    case MinHeight = 'min_height';
    case MinSide = 'min_side';
    case MaxSide = 'max_side';
    /** require_clear_edge: no pixel on its edge is fully transparent. */
    case ClearEdge = 'clear_edge';
}
