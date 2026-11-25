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

namespace UtafitiLabs\ImageBundle\Tests\Double;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The storages an application names, by service id, in memory; renditions are addressed under media.example.com.
 */
final class Storages implements ContainerInterface
{
    /** @var array<string, RecordingAdapter> */
    public array $adapters = [];

    /** @var array<string, FilesystemOperator> */
    private array $storages = [];

    public function __construct(string ...$ids)
    {
        foreach ($ids as $id) {
            $this->adapters[$id] = new RecordingAdapter();
            $this->storages[$id] = new Filesystem($this->adapters[$id], ['public_url' => 'https://media.example.com/'.str_replace('app.storage.', '', $id)]);
        }
    }

    public function get(string $id): FilesystemOperator
    {
        return $this->storages[$id] ?? throw new class(\sprintf('No storage "%s".', $id)) extends \RuntimeException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return isset($this->storages[$id]);
    }
}
