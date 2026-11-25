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

namespace UtafitiLabs\ImageBundle\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImageBundle\Engine\SvgCleaner;

final class SvgCleanerTest extends TestCase
{
    private const string SHAPE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 80"><rect width="120" height="80" fill="#336699"/></svg>';

    public function testAPlainSvgIsKept(): void
    {
        self::assertSame(self::SHAPE, (new SvgCleaner())->clean(self::SHAPE));
    }

    public function testScriptsHandlersForeignObjectsMetadataAndCommentsGo(): void
    {
        $svg = <<<'SVG'
            <?xml version="1.0"?>
            <?xml-stylesheet href="https://cdn.example.com/x.css"?>
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:editor="http://editor.example.org/ns" viewBox="0 0 10 10" onload="alert(1)" editor:version="2">
              <!-- made by hand -->
              <metadata><title>secret</title></metadata>
              <script>alert(2)</script>
              <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
              <editor:guide position="3"/>
              <defs><circle id="dot" r="2"/></defs>
              <use xlink:href="#dot" onclick="alert(3)"/>
              <use href="https://tracker.example.net/a.svg#x"/>
              <a href="javascript:alert(4)"><rect width="1" height="1"/></a>
              <image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>
              <image xlink:href="http://images.example.com/logo.png" width="1" height="1"/>
            </svg>
            SVG;

        $clean = (new SvgCleaner())->clean($svg);

        self::assertNotNull($clean);
        foreach (['<script', 'alert', 'foreignObject', 'metadata', 'secret', 'made by hand', 'xml-stylesheet', 'editor', 'tracker.example.net', 'javascript:', 'images.example.com', 'onload', 'onclick'] as $gone) {
            self::assertStringNotContainsString($gone, $clean);
        }
        self::assertStringContainsString('<circle id="dot" r="2"/>', $clean);
        self::assertStringContainsString('xlink:href="#dot"', $clean);
        self::assertStringContainsString('href="data:image/png;base64,iVBORw0KGgo="', $clean);
        self::assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $clean);
    }

    public function testAnInternalEntityIsResolvedAndTheDoctypeDropped(): void
    {
        $svg = '<!DOCTYPE svg [<!ENTITY side "64">]><svg xmlns="http://www.w3.org/2000/svg" width="&side;" height="&side;"/>';

        $clean = (new SvgCleaner())->clean($svg);

        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"/>', $clean);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'an external entity' => ['<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'];
        yield 'a public external entity' => ['<!DOCTYPE svg [<!ENTITY x PUBLIC "-//X//EN" "https://dtd.example.com/x.dtd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'];
        yield 'an external parameter entity' => ['<!DOCTYPE svg [<!ENTITY % p SYSTEM "https://dtd.example.com/p.dtd"> %p;]><svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'an entity expansion bomb' => ['<!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;"><!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;"><!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;"><!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;"><!ENTITY g "&f;&f;&f;&f;&f;&f;&f;&f;&f;&f;"><!ENTITY h "&g;&g;&g;&g;&g;&g;&g;&g;&g;&g;">]><svg xmlns="http://www.w3.org/2000/svg"><text>&h;</text></svg>'];
        yield 'no svg root' => ['<html xmlns="http://www.w3.org/1999/xhtml"/>'];
        yield 'an svg root outside the SVG namespace' => ['<svg viewBox="0 0 1 1"/>'];
        yield 'not XML' => ['<svg xmlns="http://www.w3.org/2000/svg"'];
        yield 'nothing' => [''];
    }

    #[DataProvider('refused')]
    public function testWhatCannotBeReadSafelyIsRefused(string $svg): void
    {
        self::assertNull((new SvgCleaner())->clean($svg));
    }

    /**
     * @return iterable<string, array{string, array{?int, ?int}}>
     */
    public static function dimensions(): iterable
    {
        yield 'width and height' => ['<svg xmlns="http://www.w3.org/2000/svg" width="64px" height="48" viewBox="0 0 1 1"/>', [64, 48]];
        yield 'the view box' => [self::SHAPE, [120, 80]];
        yield 'a fractional view box' => ['<svg xmlns="http://www.w3.org/2000/svg" viewBox="0,0,10.6,20.2"/>', [11, 20]];
        yield 'relative units' => ['<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="2em"/>', [null, null]];
        yield 'none' => ['<svg xmlns="http://www.w3.org/2000/svg"/>', [null, null]];
    }

    /**
     * @param array{?int, ?int} $expected
     */
    #[DataProvider('dimensions')]
    public function testTheDimensionsComeFromWidthAndHeightOrTheViewBox(string $svg, array $expected): void
    {
        self::assertSame($expected, (new SvgCleaner())->dimensions($svg));
    }
}
