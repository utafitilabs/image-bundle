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

final class UnknownProfileException extends \InvalidArgumentException implements ImageExceptionInterface
{
    /**
     * @param list<string> $known
     */
    public function __construct(string $profile, array $known)
    {
        parent::__construct(\sprintf('There is no image profile "%s"; the profiles are: %s.', $profile, [] === $known ? 'none' : implode(', ', $known)));
    }
}
