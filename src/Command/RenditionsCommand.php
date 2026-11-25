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

namespace UtafitiLabs\ImageBundle\Command;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Model\Profile;
use UtafitiLabs\ImageBundle\Service\ImageNamesInterface;
use UtafitiLabs\ImageBundle\Service\ImagesInterface;
use UtafitiLabs\ImageBundle\Service\ProfileRegistryService;

/**
 * image:renditions <profile> [name…] [--all]. Without names, the profile's names come from the application's
 * ImageNamesInterface service tagged for it, else from a listing of its folder in the originals storage.
 */
#[AsCommand(name: 'image:renditions', description: 'Writes the missing renditions of a profile\'s originals, or all of them')]
final class RenditionsCommand extends Command
{
    public function __construct(
        private readonly ImagesInterface $images,
        private readonly ProfileRegistryService $profiles,
        private readonly ContainerInterface $names,
        private readonly ContainerInterface $storages,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profile', InputArgument::REQUIRED, 'The profile')
            ->addArgument('names', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Stored names; every original of the profile when none is given')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Write every rendition again, not only the missing ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            /** @var string $name */
            $name = $input->getArgument('profile');
            $profile = $this->profiles->get($name);
        } catch (UnknownProfileException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }
        /** @var list<string> $names */
        $names = $input->getArgument('names');
        $all = (bool) $input->getOption('all');

        $originals = 0;
        $written = 0;
        $failed = 0;
        foreach ([] === $names ? $this->namesOf($profile) : $names as $name) {
            ++$originals;
            try {
                $paths = $this->images->redraw($profile->name, $name, $all);
            } catch (UnreadableImageException $exception) {
                ++$failed;
                $io->writeln(\sprintf('<error>%s: %s</error>', $name, $exception->getMessage()));
                continue;
            }
            $written += \count($paths);
            if ([] !== $paths && $output->isVerbose()) {
                $io->writeln(\sprintf('%s: %s', $name, implode(', ', $paths)));
            }
        }
        $io->writeln(\sprintf(
            '%s: %d original%s, %d rendition%s written, %d failed.',
            $profile->name,
            $originals,
            1 === $originals ? '' : 's',
            $written,
            1 === $written ? '' : 's',
            $failed,
        ));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return iterable<string>
     */
    private function namesOf(Profile $profile): iterable
    {
        if ($this->names->has($profile->name)) {
            $names = $this->names->get($profile->name);
            if ($names instanceof ImageNamesInterface) {
                yield from $names->names($profile->name);

                return;
            }
        }
        $storage = $this->storages->get($profile->originals);
        if (!$storage instanceof FilesystemOperator) {
            return;
        }
        foreach ($storage->listContents($profile->folder, false) as $item) {
            if ($item instanceof FileAttributes) {
                yield basename($item->path());
            }
        }
    }
}
