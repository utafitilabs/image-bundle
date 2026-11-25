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

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * A storage in memory that remembers the options each file was written with.
 */
final class RecordingAdapter extends InMemoryFilesystemAdapter
{
    /** @var array<string, array{CacheControl: mixed, ContentType: mixed}> */
    public array $options = [];

    public function write(string $path, string $contents, Config $config): void
    {
        $this->options[$path] = ['CacheControl' => $config->get('CacheControl'), 'ContentType' => $config->get('ContentType')];
        parent::write($path, $contents, $config);
    }
}
