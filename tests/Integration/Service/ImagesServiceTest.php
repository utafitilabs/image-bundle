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

namespace UtafitiLabs\ImageBundle\Tests\Integration\Service;

use Imagine\Gd\DriverInfo;
use Imagine\Gd\Imagine;
use Imagine\Image\Metadata\ExifMetadataReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use UtafitiLabs\ImageBundle\Engine\ClearPixelScanner;
use UtafitiLabs\ImageBundle\Engine\ExifReader;
use UtafitiLabs\ImageBundle\Engine\RenditionEngine;
use UtafitiLabs\ImageBundle\Engine\SvgCleaner;
use UtafitiLabs\ImageBundle\Event\RenditionsFailed;
use UtafitiLabs\ImageBundle\Event\RenditionsWritten;
use UtafitiLabs\ImageBundle\Exception\InvalidNameException;
use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Exception\UnknownSizeException;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Message\MakeRenditions;
use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;
use UtafitiLabs\ImageBundle\Model\Refusal;
use UtafitiLabs\ImageBundle\Model\RefusalReasonEnum;
use UtafitiLabs\ImageBundle\Service\ImageCheckerService;
use UtafitiLabs\ImageBundle\Service\ImagesService;
use UtafitiLabs\ImageBundle\Service\ProfileRegistryService;
use UtafitiLabs\ImageBundle\Tests\Double\Profiles;
use UtafitiLabs\ImageBundle\Tests\Double\RecordingBus;
use UtafitiLabs\ImageBundle\Tests\Double\RecordingDispatcher;
use UtafitiLabs\ImageBundle\Tests\Double\Storages;
use UtafitiLabs\ImageBundle\Tests\Double\SyntheticImage;

final class ImagesServiceTest extends TestCase
{
    private const string ORIGINALS = 'app.storage.originals';
    private const string RENDITIONS = 'app.storage.renditions';

    private Storages $storages;
    private RecordingDispatcher $events;
    private RecordingBus $bus;
    private ImagesService $images;

    protected function setUp(): void
    {
        $this->storages = new Storages(self::ORIGINALS, self::RENDITIONS, 'app.storage.cdn');
        $this->events = new RecordingDispatcher();
        $this->bus = new RecordingBus();
        $this->images = $this->images($this->bus);
    }

    public function testStoringKeepsTheOriginalAndWritesEveryRendition(): void
    {
        $source = SyntheticImage::jpeg(1600, 1200, orientation: 6, takenAt: '2026:03:14 15:09:26');

        $stored = $this->images->store('portrait', $this->file($source), 'jane-doe');

        self::assertMatchesRegularExpression('/^jane-doe-[0-9a-f]{16}\.jpg$/', $stored->name);
        self::assertSame('image/jpeg', $stored->mimeType);
        self::assertSame(\strlen($source), $stored->bytes);
        self::assertSame([1200, 1600], [$stored->width, $stored->height]);
        self::assertSame(hash('sha256', $source), $stored->sha256);
        self::assertEquals(new \DateTimeImmutable('2026-03-14 15:09:26'), $stored->takenAt);

        self::assertSame($source, $this->storages->get(self::ORIGINALS)->read('people/'.$stored->name));
        $stem = substr($stored->name, 0, -4);
        $written = [];
        foreach ([320 => [320, 400], 800 => [800, 1000], 1080 => [1080, 1350]] as $size => $dimensions) {
            $path = 'people/'.$stem.'-'.$size.'.jpg';
            self::assertSame($dimensions, SyntheticImage::size($this->storages->get(self::RENDITIONS)->read($path)));
            self::assertSame(['CacheControl' => 'public, max-age=604800', 'ContentType' => 'image/jpeg'], $this->storages->adapters[self::RENDITIONS]->options[$path]);
            $written[] = $path;
        }
        self::assertEquals([new RenditionsWritten('portrait', $stored->name, $written)], $this->events->events);
    }

    public function testAPathAndNoLabelAreEnough(): void
    {
        $stored = $this->images->store('portrait', $this->file(SyntheticImage::opaquePng(900, 1200)));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $stored->name);
        self::assertSame('image/png', $stored->mimeType);
        self::assertTrue($this->storages->get(self::RENDITIONS)->fileExists('people/'.substr($stored->name, 0, -4).'-800.jpg'));
    }

    public function testNothingIsWrittenWhenTheOriginalCannotBeDrawn(): void
    {
        try {
            $this->images->storeAs('portrait', 'broken.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\x00", 40));
            self::fail('No exception.');
        } catch (UnreadableImageException $exception) {
            self::assertSame(FailureReasonEnum::Unreadable, $exception->reason);
        }

        self::assertSame([], $this->storages->get(self::ORIGINALS)->listContents('', true)->toArray());
        self::assertSame([], $this->storages->get(self::RENDITIONS)->listContents('', true)->toArray());
    }

    public function testAnSvgIsRefusedByAProfileThatDoesNotSanitise(): void
    {
        $this->expectException(UnreadableImageException::class);

        $this->images->storeAs('portrait', 'drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    }

    public function testLabelsAndNamesStayInsideTheFolder(): void
    {
        foreach (['../escape', 'two words', '-dash'] as $label) {
            try {
                $this->images->store('portrait', $this->file(SyntheticImage::jpeg(900, 1200)), $label);
                self::fail('No exception for "'.$label.'".');
            } catch (InvalidNameException) {
            }
        }
        foreach (['../x.jpg', 'a/b.jpg', '.hidden.jpg', 'noextension', 'x.bmp'] as $name) {
            try {
                $this->images->url('portrait', $name, 320);
                self::fail('No exception for "'.$name.'".');
            } catch (InvalidNameException) {
            }
        }
        $this->addToAssertionCount(8);
    }

    public function testABadgeIsTrimmedAndItsSmallSizeKeepsTheStoredName(): void
    {
        $this->images->storeAs('badge', 'river-club-0123456789abcdef.png', SyntheticImage::clearPng(1200, 1000, [100, 300, 1000, 400]));

        $renditions = $this->storages->get(self::RENDITIONS);
        self::assertSame([256, 102], SyntheticImage::size($renditions->read('badges/river-club-0123456789abcdef.png')));
        self::assertSame([512, 205], SyntheticImage::size($renditions->read('badges/river-club-0123456789abcdef-512.png')));
        self::assertSame('https://media.example.com/renditions/badges/river-club-0123456789abcdef.png', $this->images->url('badge', 'river-club-0123456789abcdef.png', 256));
    }

    public function testASanitisedSvgHasOneCleanCopyForEverySize(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 200" onload="alert(1)"><script>alert(2)</script><rect width="300" height="200"/></svg>';

        $stored = $this->images->store('badge', $this->file($svg), 'hill-town');

        self::assertMatchesRegularExpression('/^hill-town-[0-9a-f]{16}\.svg$/', $stored->name);
        self::assertSame(['image/svg+xml', 300, 200, null], [$stored->mimeType, $stored->width, $stored->height, $stored->takenAt]);
        self::assertSame($svg, $this->storages->get(self::ORIGINALS)->read('badges/'.$stored->name));
        $copy = $this->storages->get(self::RENDITIONS)->read('badges/'.$stored->name);
        self::assertStringNotContainsString('alert', $copy);
        self::assertSame(['CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/svg+xml'], $this->storages->adapters[self::RENDITIONS]->options['badges/'.$stored->name]);
        $url = 'https://media.example.com/renditions/badges/'.$stored->name;
        self::assertSame([$url, $url, $url, $url, $url], [
            $this->images->url('badge', $stored->name, 256),
            $this->images->url('badge', $stored->name, 512),
            $this->images->url('badge', $stored->name, 999),
            $this->images->fallbackUrl('badge', $stored->name),
            $this->images->srcset('badge', $stored->name),
        ]);
        self::assertSame(['badges/'.$stored->name], $this->images->redraw('badge', $stored->name, true));
    }

    public function testAnAsyncProfileKeepsTheOriginalAndQueuesItsRenditions(): void
    {
        $stored = $this->images->store('gallery', $this->file(SyntheticImage::jpeg(2400, 1600)));

        self::assertEquals([new MakeRenditions('gallery', $stored->name)], $this->bus->messages);
        self::assertTrue($this->storages->get(self::ORIGINALS)->fileExists('gallery/'.$stored->name));
        self::assertSame([], $this->storages->get(self::RENDITIONS)->listContents('', true)->toArray());
        self::assertSame([], $this->events->events);
    }

    public function testAnAsyncProfileNeedsABus(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The image profile "gallery" makes its renditions through Messenger');

        $this->images(null)->store('gallery', $this->file(SyntheticImage::jpeg(400, 300)));
    }

    public function testRedrawWritesWhatIsMissingOrEverything(): void
    {
        $stored = $this->images->store('gallery', $this->file(SyntheticImage::jpeg(2400, 1600)));
        $stem = 'gallery/'.substr($stored->name, 0, -4);
        $all = [$stem.'-320.webp', $stem.'-800.webp', $stem.'-1280.webp', $stem.'-2000.webp', $stem.'-1280.jpg'];

        self::assertSame($all, $this->images->redraw('gallery', $stored->name));
        self::assertSame([1280, 853], SyntheticImage::size($this->storages->get(self::RENDITIONS)->read($stem.'-1280.jpg')));
        self::assertSame(['CacheControl' => 'public, max-age=31536000, immutable', 'ContentType' => 'image/webp'], $this->storages->adapters[self::RENDITIONS]->options[$stem.'-2000.webp']);

        $this->storages->get(self::RENDITIONS)->delete($stem.'-800.webp');
        self::assertSame([$stem.'-800.webp'], $this->images->redraw('gallery', $stored->name));
        self::assertSame([], $this->images->redraw('gallery', $stored->name));
        self::assertSame($all, $this->images->redraw('gallery', $stored->name, true));
        self::assertEquals([
            new RenditionsWritten('gallery', $stored->name, $all),
            new RenditionsWritten('gallery', $stored->name, [$stem.'-800.webp']),
            new RenditionsWritten('gallery', $stored->name, []),
            new RenditionsWritten('gallery', $stored->name, $all),
        ], $this->events->events);
    }

    public function testRedrawSaysWhenTheOriginalIsMissingOrUnreadable(): void
    {
        try {
            $this->images->redraw('gallery', 'gone.jpg');
            self::fail('No exception.');
        } catch (UnreadableImageException $exception) {
            self::assertSame(FailureReasonEnum::Missing, $exception->reason);
        }
        $this->storages->get(self::ORIGINALS)->write('gallery/garbled.jpg', 'not a picture');
        try {
            $this->images->redraw('gallery', 'garbled.jpg');
            self::fail('No exception.');
        } catch (UnreadableImageException $exception) {
            self::assertSame(FailureReasonEnum::Unreadable, $exception->reason);
        }

        self::assertEquals([
            new RenditionsFailed('gallery', 'gone.jpg', FailureReasonEnum::Missing),
            new RenditionsFailed('gallery', 'garbled.jpg', FailureReasonEnum::Unreadable),
        ], $this->events->events);
    }

    public function testAddresses(): void
    {
        self::assertSame('https://media.example.com/renditions/people/c0ffee-800.jpg', $this->images->url('portrait', 'c0ffee.png', 800));
        self::assertSame('https://media.example.com/renditions/gallery/c0ffee-1280.jpg', $this->images->fallbackUrl('gallery', 'c0ffee.jpg'));
        self::assertSame('https://media.example.com/cdn/cutouts/c0ffee-400.png', $this->images->url('cutout', 'c0ffee.png', 400));
    }

    public function testAnUnknownSizeOrProfileOrFallbackIsSaidClearly(): void
    {
        $this->assertThrows(UnknownSizeException::class, 'The image profile "portrait" has no size 500; its sizes are: 320, 800, 1080.', fn () => $this->images->url('portrait', 'c0ffee.jpg', 500));
        $this->assertThrows(UnknownSizeException::class, 'The image profile "portrait" has no fallback.', fn () => $this->images->fallbackUrl('portrait', 'c0ffee.jpg'));
        $this->assertThrows(UnknownProfileException::class, 'There is no image profile "poster"; the profiles are: portrait, cutout, badge, gallery.', fn () => $this->images->url('poster', 'c0ffee.jpg', 320));
    }

    public function testSrcsetDescribesEachWidthNoWiderThanTheOriginal(): void
    {
        $base = 'https://media.example.com/renditions/gallery/c0ffee';

        self::assertSame("$base-320.webp 320w, $base-800.webp 800w, $base-1280.webp 1280w, $base-2000.webp 2000w", $this->images->srcset('gallery', 'c0ffee.jpg'));
        self::assertSame("$base-320.webp 320w, $base-800.webp 800w, $base-1280.webp 1000w", $this->images->srcset('gallery', 'c0ffee.jpg', 1000));
        self::assertSame('https://media.example.com/renditions/people/c0ffee-320.jpg 320w, https://media.example.com/renditions/people/c0ffee-800.jpg 800w, https://media.example.com/renditions/people/c0ffee-1080.jpg 1080w', $this->images->srcset('portrait', 'c0ffee.jpg'));
        $this->assertThrows(UnknownSizeException::class, 'The sizes of the image profile "cutout" are not widths', fn () => $this->images->srcset('cutout', 'c0ffee.png'));
    }

    public function testDeleteRemovesTheOriginalAndEveryRendition(): void
    {
        $photo = $this->images->store('gallery', $this->file(SyntheticImage::jpeg(2400, 1600)));
        $this->images->redraw('gallery', $photo->name);
        $svg = $this->images->store('badge', $this->file('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>'));
        $png = $this->images->store('badge', $this->file(SyntheticImage::clearPng(600, 600, [10, 10, 500, 500])));

        $this->images->delete('gallery', $photo->name);
        $this->images->delete('badge', $svg->name);
        $this->images->delete('badge', $png->name);

        self::assertSame([], $this->storages->get(self::ORIGINALS)->listContents('', true)->toArray());
        self::assertSame([], array_filter($this->storages->get(self::RENDITIONS)->listContents('', true)->toArray(), static fn ($item): bool => $item->isFile()));
    }

    public function testAProfileWritesToTheStoragesItNames(): void
    {
        $stored = $this->images->store('cutout', $this->file(SyntheticImage::clearPng(1000, 2400, [100, 100, 800, 2200])));

        self::assertSame([1000, 2400], [$stored->width, $stored->height]);
        self::assertSame([800, 1920], SyntheticImage::size($this->storages->get('app.storage.cdn')->read('cutouts/'.substr($stored->name, 0, -4).'-1920.png')));
        self::assertTrue($this->storages->get(self::ORIGINALS)->fileExists('cutouts/'.$stored->name));
        self::assertFalse($this->storages->get(self::RENDITIONS)->fileExists('cutouts/'.substr($stored->name, 0, -4).'-400.png'));
    }

    public function testCheckUsesTheProfilesRules(): void
    {
        self::assertEquals(new Refusal(RefusalReasonEnum::MinWidth, 800), $this->images->check('portrait', new File($this->file(SyntheticImage::jpeg(600, 1000)))));
        self::assertNull($this->images->check('portrait', new File($this->file(SyntheticImage::jpeg(800, 1000)))));
    }

    private function images(?RecordingBus $bus): ImagesService
    {
        $profiles = new ProfileRegistryService(Profiles::processed([
            'portrait' => [
                'folder' => 'people',
                'min_width' => 800,
                'fit' => 'cover',
                'aspect' => '4:5',
                'sizes' => [320, 800, 1080],
                'format' => 'jpeg',
                'interlace' => true,
                'background' => '#FFFFFF',
                'cache_control' => 'public, max-age=604800',
            ],
            'cutout' => [
                'folder' => 'cutouts',
                'accept' => ['image/png'],
                'renditions' => 'app.storage.cdn',
                'fit' => 'height',
                'sizes' => [400, 1920],
                'format' => 'png',
            ],
            'badge' => [
                'folder' => 'badges',
                'accept' => ['image/png', 'image/svg+xml'],
                'svg' => 'sanitise',
                'trim' => 'clear',
                'fit' => 'contain',
                'sizes' => [256 => ['path' => '{name}'], 512 => null],
                'format' => 'png',
            ],
            'gallery' => [
                'fit' => 'width',
                'sizes' => [320, 800, 1280, 2000],
                'format' => 'webp',
                'background' => '#FFFFFF',
                'fallback' => ['size' => 1280, 'format' => 'jpeg', 'quality' => 72, 'interlace' => true],
                'generate' => 'async',
            ],
        ]));
        $imagine = new Imagine();
        $metadata = new ExifMetadataReader();
        $imagine->setMetadataReader($metadata);
        $info = DriverInfo::get() ?? throw new \LogicException('No GD.');
        $scanner = new ClearPixelScanner();
        $cleaner = new SvgCleaner();

        return new ImagesService(
            $profiles,
            $this->storages,
            new RenditionEngine($imagine, $scanner, $info, 'gd'),
            new ImageCheckerService($profiles, $imagine, $scanner, $cleaner),
            $cleaner,
            new ExifReader($metadata),
            $bus,
            $this->events,
        );
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private function assertThrows(string $exception, string $message, callable $call): void
    {
        try {
            $call();
            self::fail('No exception.');
        } catch (\Throwable $thrown) {
            self::assertInstanceOf($exception, $thrown);
            self::assertStringContainsString($message, $thrown->getMessage());
        }
    }

    private function file(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'image-bundle-');
        file_put_contents($path, $bytes);
        register_shutdown_function(static fn () => @unlink($path));

        return $path;
    }
}
