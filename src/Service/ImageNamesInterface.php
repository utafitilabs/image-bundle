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

/**
 * The stored names of a profile's originals, from the application's own records, for image:renditions. Tag the
 * service "utafitilabs_image.names" with the profile it answers for ({profile: <name>}); one service may carry the
 * tag for several profiles. A profile without one is listed from its folder in the originals storage, which is only
 * right when no other profile shares that folder.
 */
interface ImageNamesInterface
{
    /**
     * @return iterable<string>
     */
    public function names(string $profile): iterable;
}
