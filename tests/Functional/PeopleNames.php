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

use UtafitiLabs\ImageBundle\Service\ImageNamesInterface;

/**
 * An application's records of people, which keep a portrait and a cut-out each in the same folder.
 */
final class PeopleNames implements ImageNamesInterface
{
    /** @var array<string, list<string>> */
    public static array $names = ['portrait' => [], 'cutout' => []];

    public function names(string $profile): iterable
    {
        return self::$names[$profile] ?? [];
    }
}
