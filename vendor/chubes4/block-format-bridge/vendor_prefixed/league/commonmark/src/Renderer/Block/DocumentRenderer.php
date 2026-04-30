<?php

declare (strict_types=1);
/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * Original code based on the CommonMark JS reference parser (https://bitly.com/commonmark-js)
 *  - (c) John MacFarlane
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace BlockFormatBridge\Vendor\League\CommonMark\Renderer\Block;

use BlockFormatBridge\Vendor\League\CommonMark\Node\Block\Document;
use BlockFormatBridge\Vendor\League\CommonMark\Node\Node;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
final class DocumentRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @param Document $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Document::assertInstanceOf($node);
        $wholeDoc = $childRenderer->renderNodes($node->children());
        return $wholeDoc === '' ? '' : $wholeDoc . "\n";
    }
    public function getXmlTagName(Node $node): string
    {
        return 'document';
    }
    /**
     * {@inheritDoc}
     */
    public function getXmlAttributes(Node $node): array
    {
        return ['xmlns' => 'http://commonmark.org/xml/1.0'];
    }
}
