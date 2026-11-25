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

namespace UtafitiLabs\ImageBundle\Service;

use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Model\Profile;

/**
 * The configured profiles by name.
 */
final readonly class ProfileRegistryService
{
    /** @var array<string, Profile> */
    private array $profiles;

    /**
     * @param array<string, array<string, mixed>> $profiles the utafitilabs_image.profiles parameter
     */
    public function __construct(array $profiles)
    {
        $made = [];
        foreach ($profiles as $name => $profile) {
            $made[$name] = Profile::fromConfig($name, $profile);
        }
        $this->profiles = $made;
    }

    /**
     * @throws UnknownProfileException
     */
    public function get(string $name): Profile
    {
        return $this->profiles[$name] ?? throw new UnknownProfileException($name, $this->names());
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->profiles));
    }
}
