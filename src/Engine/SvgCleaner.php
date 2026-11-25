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

namespace UtafitiLabs\ImageBundle\Engine;

/**
 * Writes an SVG again without what could run or reach out when a browser shows it.
 *
 * Kept: elements of the SVG namespace and attributes without a namespace or in the XLink and XML namespaces. Removed:
 * comments, processing instructions, metadata, every element and attribute of another namespace (editor data), script,
 * foreignObject, every on* event handler, and every href other than to the document's own parts (#id) or to an
 * embedded PNG, JPEG, GIF or WebP. A document type that declares an external entity (SYSTEM or PUBLIC) is not read at
 * all; internal entities are resolved and the document type dropped. The document is parsed with LIBXML_NONET, so
 * libxml never fetches anything ("Disable network access when loading documents",
 * https://www.php.net/manual/en/libxml.constants.php), and without LIBXML_PARSEHUGE, so libxml's own limit on entity
 * expansion stops an expansion bomb. It is an allow-list by namespace, not a CSS parser: a style attribute or a
 * style element is kept as it is.
 */
final class SvgCleaner
{
    private const string SVG = 'http://www.w3.org/2000/svg';
    private const string XLINK = 'http://www.w3.org/1999/xlink';
    private const string XML = 'http://www.w3.org/XML/1998/namespace';
    private const int FLAGS = \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING;

    /** The cleaned SVG; null when the text is not an SVG document that can be read safely. */
    public function clean(string $svg): ?string
    {
        if ('' === trim($svg)) {
            return null;
        }
        $document = new \DOMDocument();
        if (!@$document->loadXML($svg, self::FLAGS)) {
            return null;
        }
        if (null !== $document->doctype) {
            if (1 === preg_match('/<!ENTITY\s+(%\s*)?\S+\s+(SYSTEM|PUBLIC)\b/i', (string) $document->doctype->internalSubset)) {
                return null;
            }
            $document = new \DOMDocument();
            if (!@$document->loadXML($svg, self::FLAGS | \LIBXML_NOENT) || null === $document->doctype) {
                return null;
            }
            $document->removeChild($document->doctype);
        }
        $root = $document->documentElement;
        if (null === $root || 'svg' !== $root->localName || self::SVG !== $root->namespaceURI) {
            return null;
        }
        $this->cleanElement($root);
        foreach ((new \DOMXPath($document))->query('namespace::*', $root) ?: [] as $namespace) {
            if ($namespace instanceof \DOMNameSpaceNode && !\in_array($namespace->nodeValue, [self::SVG, self::XLINK, self::XML], true)) {
                $root->removeAttributeNS((string) $namespace->nodeValue, (string) $namespace->prefix);
            }
        }
        $written = $document->saveXML($root);

        return false === $written ? null : $written;
    }

    /**
     * The width and height of a cleaned SVG in pixels, from its width and height attributes in px or plain numbers,
     * else from its viewBox; null for what it does not say.
     *
     * @return array{?int, ?int}
     */
    public function dimensions(string $svg): array
    {
        $document = new \DOMDocument();
        if (!@$document->loadXML($svg, self::FLAGS) || null === $root = $document->documentElement) {
            return [null, null];
        }
        $length = static fn (string $value): ?int => 1 === preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:px)?\s*$/', $value, $match) ? max(1, (int) round((float) $match[1])) : null;
        $width = $length($root->getAttribute('width'));
        $height = $length($root->getAttribute('height'));
        if (null !== $width && null !== $height) {
            return [$width, $height];
        }
        $box = preg_split('/[\s,]+/', trim($root->getAttribute('viewBox'))) ?: [];
        if (4 === \count($box) && is_numeric($box[2]) && is_numeric($box[3]) && (float) $box[2] > 0 && (float) $box[3] > 0) {
            return [max(1, (int) round((float) $box[2])), max(1, (int) round((float) $box[3]))];
        }

        return [$width, $height];
    }

    private function cleanElement(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (self::SVG !== $child->namespaceURI || \in_array($child->localName, ['script', 'foreignObject', 'metadata'], true)) {
                    $element->removeChild($child);
                } else {
                    $this->cleanElement($child);
                }
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $element->removeChild($child);
            }
        }
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $namespace = $attribute->namespaceURI;
            $keep = (null === $namespace || self::XLINK === $namespace || self::XML === $namespace)
                && !str_starts_with(strtolower($attribute->localName ?? ''), 'on')
                && ('href' !== $attribute->localName || 1 === preg_match('~^(#|data:image/(png|jpeg|gif|webp)[;,])~i', trim($attribute->value)));
            if (!$keep) {
                $element->removeAttributeNode($attribute);
            }
        }
    }
}
