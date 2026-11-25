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

use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Twig\Environment;
use UtafitiLabs\ImageBundle\Command\RenditionsCommand;
use UtafitiLabs\ImageBundle\Event\RenditionsFailed;
use UtafitiLabs\ImageBundle\Event\RenditionsWritten;
use UtafitiLabs\ImageBundle\Message\MakeRenditions;
use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;
use UtafitiLabs\ImageBundle\Service\ImagesInterface;
use UtafitiLabs\ImageBundle\Service\ImagesService;
use UtafitiLabs\ImageBundle\Tests\Double\SyntheticImage;
use UtafitiLabs\ImageBundle\UtafitiLabsImageBundle;

/**
 * Boots a kernel with the bundle configured as an application would, and uses it through the container, Messenger,
 * the console and Twig.
 */
final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        PeopleNames::$names = ['portrait' => [], 'cutout' => []];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        restore_exception_handler();
    }

    public function testTheProcessedProfilesAreAParameterWithTheirStorages(): void
    {
        $profiles = self::getContainer()->getParameter('utafitilabs_image.profiles');

        self::assertIsArray($profiles);
        self::assertSame(['portrait', 'cutout', 'gallery'], array_keys($profiles));
        self::assertIsArray($profiles['portrait']);
        self::assertSame('app.storage.originals', $profiles['portrait']['originals']);
        self::assertSame('app.storage.renditions', $profiles['portrait']['renditions']);
    }

    public function testTheImagesServiceAndTheCheckerArePublicAndAutowirable(): void
    {
        $container = self::$kernel?->getContainer();
        self::assertNotNull($container);
        self::assertInstanceOf(ImagesService::class, $container->get('utafitilabs_image.images'));
        self::assertTrue($container->has('utafitilabs_image.checker'));

        $consumer = self::getContainer()->get('app.image_services_consumer');
        self::assertInstanceOf(ImageServicesConsumer::class, $consumer);
        self::assertSame($container->get('utafitilabs_image.images'), $consumer->images);
        self::assertSame($container->get('utafitilabs_image.checker'), $consumer->checker);
    }

    public function testInternalServicesAreHiddenAndPrivate(): void
    {
        $builder = new ContainerBuilder(new ParameterBag(['kernel.environment' => 'test', 'kernel.build_dir' => sys_get_temp_dir(), 'kernel.debug' => false]));
        $extension = (new UtafitiLabsImageBundle())->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([['storages' => ['originals' => 'app.storage.originals', 'renditions' => 'app.storage.renditions']]], $builder);

        foreach (['.utafitilabs_image.engine', '.utafitilabs_image.trimmer', '.utafitilabs_image.svg_cleaner', '.utafitilabs_image.exif_reader'] as $id) {
            self::assertTrue($builder->hasDefinition($id), $id);
            self::assertTrue($builder->getDefinition($id)->isPrivate(), $id);
        }
        self::assertTrue($builder->getDefinition('utafitilabs_image.images')->isPublic());
        self::assertTrue($builder->getDefinition('utafitilabs_image.checker')->isPublic());
        self::assertSame(['console.command' => [[]]], $builder->getDefinition('utafitilabs_image.command.renditions')->getTags());
        self::assertSame(['messenger.message_handler' => [['handles' => MakeRenditions::class]]], $builder->getDefinition('utafitilabs_image.message_handler.make_renditions')->getTags());
        self::assertSame(['twig.extension' => [[]]], $builder->getDefinition('utafitilabs_image.twig_extension')->getTags());
        foreach ($builder->getDefinitions() as $id => $definition) {
            if (str_contains($id, 'utafitilabs_image')) {
                self::assertFalse($definition->isAutowired(), $id);
                self::assertFalse($definition->isAutoconfigured(), $id);
            }
        }
    }

    public function testAStoredPortraitIsDrawnAtOnce(): void
    {
        $stored = $this->images()->storeAs('portrait', 'ada-0123456789abcdef.jpg', SyntheticImage::jpeg(1600, 1200));

        self::assertSame([320, 400], SyntheticImage::size($this->storage('app.storage.renditions')->read('people/ada-0123456789abcdef-320.jpg')));
        self::assertSame('https://media.example.com/people/ada-0123456789abcdef-800.jpg', $this->images()->url('portrait', $stored->name, 800));
        self::assertEquals([new RenditionsWritten('portrait', $stored->name, ['people/ada-0123456789abcdef-320.jpg', 'people/ada-0123456789abcdef-800.jpg'])], $this->events());
    }

    public function testAnAsyncProfileQueuesMakeRenditionsAndTheHandlerDrawsThemOnceOnRedelivery(): void
    {
        $stored = $this->images()->storeAs('gallery', 'c0ffee.jpg', SyntheticImage::jpeg(1200, 900));
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $queued = [...$transport->getSent()];
        self::assertCount(1, $queued);
        self::assertEquals(new MakeRenditions('gallery', 'c0ffee.jpg'), $queued[0]->getMessage());
        self::assertFalse($this->storage('app.storage.renditions')->fileExists('gallery/c0ffee-320.webp'));

        $this->receive($queued[0]);
        $this->receive($queued[0]);

        self::assertSame([800, 600], SyntheticImage::size($this->storage('app.storage.renditions')->read('gallery/c0ffee-800.jpg')));
        self::assertEquals([
            new RenditionsWritten('gallery', $stored->name, ['gallery/c0ffee-320.webp', 'gallery/c0ffee-800.webp', 'gallery/c0ffee-800.jpg']),
            new RenditionsWritten('gallery', $stored->name, []),
        ], $this->events());
    }

    public function testAMessageForAnOriginalDeletedMeanwhileEndsInAFailureEventNotARetry(): void
    {
        $this->receive(new Envelope(new MakeRenditions('gallery', 'deleted.jpg')));

        self::assertEquals([new RenditionsFailed('gallery', 'deleted.jpg', FailureReasonEnum::Missing)], $this->events());
    }

    public function testTheCommandIsRegisteredLazily(): void
    {
        $command = $this->application()->find('image:renditions');

        self::assertInstanceOf(LazyCommand::class, $command);
        self::assertSame('Writes the missing renditions of a profile\'s originals, or all of them', $command->getDescription());
        self::assertInstanceOf(RenditionsCommand::class, $command->getCommand());
    }

    public function testTheCommandAsksTheApplicationForTheNamesOfAProfile(): void
    {
        $this->images()->storeAs('portrait', 'ada.jpg', SyntheticImage::jpeg(800, 1000));
        $this->images()->storeAs('cutout', 'ada.png', SyntheticImage::clearPng(400, 800, [50, 50, 300, 700]));
        PeopleNames::$names = ['portrait' => ['ada.jpg'], 'cutout' => ['ada.png']];
        $this->storage('app.storage.renditions')->delete('people/ada-800.jpg');

        [$code, $display] = $this->command(['profile' => 'portrait']);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('portrait: 1 original, 1 rendition written, 0 failed.', $display);
        self::assertTrue($this->storage('app.storage.renditions')->fileExists('people/ada-800.jpg'));

        [$code, $display] = $this->command(['profile' => 'cutout', '--all' => true]);
        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('cutout: 1 original, 1 rendition written, 0 failed.', $display);
    }

    public function testWithoutANamesServiceTheCommandListsTheProfilesFolder(): void
    {
        $this->images()->storeAs('gallery', 'one.jpg', SyntheticImage::jpeg(400, 300));
        $this->images()->storeAs('gallery', 'two.png', SyntheticImage::opaquePng(400, 300));

        [$code, $display] = $this->command(['profile' => 'gallery', '-v' => true]);

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('gallery: 2 originals, 6 renditions written, 0 failed.', $display);
        self::assertStringContainsString('one.jpg: gallery/one-320.webp, gallery/one-800.webp, gallery/one-800.jpg', $display);
    }

    public function testTheCommandTakesNamesAndReportsWhatFailed(): void
    {
        $this->images()->storeAs('gallery', 'kept.jpg', SyntheticImage::jpeg(400, 300));

        [$code, $display] = $this->command(['profile' => 'gallery', 'names' => ['kept.jpg', 'gone.jpg']]);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('gone.jpg: The original gallery/gone.jpg is missing.', $display);
        self::assertStringContainsString('gallery: 2 originals, 3 renditions written, 1 failed.', $display);
    }

    public function testTheCommandNamesTheProfilesWhenOneIsUnknown(): void
    {
        [$code, $display] = $this->command(['profile' => 'poster']);

        self::assertSame(Command::INVALID, $code);
        self::assertStringContainsString('There is no image profile "poster"; the profiles are: portrait, cutout, gallery.', (string) preg_replace('/\s+/', ' ', $display));
    }

    public function testTwigGivesAddressesAndASrcset(): void
    {
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate(<<<'TWIG'
            <img src="{{ image_url('gallery', name, 320) }}" srcset="{{ image_srcset('gallery', name, 500) }}" data-fallback="{{ image_fallback('gallery', name) }}">
            TWIG)->render(['name' => 'c0ffee.jpg']);

        self::assertSame('<img src="https://media.example.com/gallery/c0ffee-320.webp" srcset="https://media.example.com/gallery/c0ffee-320.webp 320w, https://media.example.com/gallery/c0ffee-800.webp 500w" data-fallback="https://media.example.com/gallery/c0ffee-800.jpg">', $html);
    }

    private function images(): ImagesInterface
    {
        $images = self::getContainer()->get('utafitilabs_image.images');
        self::assertInstanceOf(ImagesInterface::class, $images);

        return $images;
    }

    private function storage(string $id): FilesystemOperator
    {
        $storage = self::getContainer()->get($id);
        self::assertInstanceOf(FilesystemOperator::class, $storage);

        return $storage;
    }

    /** @return list<object> */
    private function events(): array
    {
        $events = self::getContainer()->get('app.recorded_events');
        self::assertInstanceOf(RecordedEvents::class, $events);

        return $events->events;
    }

    private function receive(Envelope $envelope): void
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($envelope->with(new ReceivedStamp('async')));
    }

    private function application(): Application
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);

        return $application;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function command(array $input): array
    {
        $output = new BufferedOutput();
        $code = $this->application()->run(new ArrayInput(['command' => 'image:renditions', ...$input]), $output);

        return [$code, $output->fetch()];
    }
}
