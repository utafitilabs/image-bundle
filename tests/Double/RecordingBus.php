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

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return Envelope::wrap($message, $stamps);
    }
}
