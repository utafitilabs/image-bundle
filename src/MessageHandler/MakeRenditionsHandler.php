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

namespace UtafitiLabs\ImageBundle\MessageHandler;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use UtafitiLabs\ImageBundle\Exception\InvalidNameException;
use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Message\MakeRenditions;
use UtafitiLabs\ImageBundle\Service\ImagesInterface;

/**
 * Writes the renditions a stored original lacks. A redelivered message finds nothing missing and writes nothing. An
 * original that is missing or unreadable has been announced by RenditionsFailed and is not retried; a message for an
 * unknown profile or name is not retried either ("UnrecoverableMessageHandlingException … the message will not be
 * retried", https://symfony.com/doc/current/messenger.html#avoiding-retrying). A storage error is thrown, so
 * Messenger retries it.
 */
final readonly class MakeRenditionsHandler
{
    public function __construct(private ImagesInterface $images)
    {
    }

    public function __invoke(MakeRenditions $message): void
    {
        try {
            $this->images->redraw($message->profile, $message->name);
        } catch (UnreadableImageException) {
        } catch (UnknownProfileException|InvalidNameException $exception) {
            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }
    }
}
