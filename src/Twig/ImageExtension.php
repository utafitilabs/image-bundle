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

namespace UtafitiLabs\ImageBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use UtafitiLabs\ImageBundle\Service\ImagesInterface;

/**
 * image_url(profile, name, size), image_srcset(profile, name, originalWidth?) and image_fallback(profile, name). The
 * markup around them (picture, sizes, loading) stays in the application's templates.
 */
final class ImageExtension extends AbstractExtension
{
    public function __construct(private readonly ImagesInterface $images)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_url', $this->images->url(...)),
            new TwigFunction('image_srcset', $this->images->srcset(...)),
            new TwigFunction('image_fallback', $this->images->fallbackUrl(...)),
        ];
    }
}
