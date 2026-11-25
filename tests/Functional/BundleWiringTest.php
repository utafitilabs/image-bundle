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

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testTheProcessedProfilesAreAParameter(): void
    {
        self::bootKernel();

        $profiles = self::getContainer()->getParameter('utafitilabs_image.profiles');

        self::assertIsArray($profiles);
        self::assertArrayHasKey('thumb', $profiles);
        self::assertIsArray($profiles['thumb']);
        self::assertSame('app.storage.originals', $profiles['thumb']['originals']);
        self::assertSame('app.storage.renditions', $profiles['thumb']['renditions']);
    }
}
