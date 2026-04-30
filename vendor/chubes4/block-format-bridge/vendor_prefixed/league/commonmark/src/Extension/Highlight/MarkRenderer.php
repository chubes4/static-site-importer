<?php

declare (strict_types=1);
/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\Highlight;

use BlockFormatBridge\Vendor\League\CommonMark\Node\Node;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Util\HtmlElement;
use BlockFormatBridge\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
final class MarkRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /**
     * @param Mark $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Mark::assertInstanceOf($node);
        return new HtmlElement('mark', $node->data->get('attributes'), $childRenderer->renderNodes($node->children()));
    }
    public function getXmlTagName(Node $node): string
    {
        return 'mark';
    }
    /**
     * {@inheritDoc}
     */
    public function getXmlAttributes(Node $node): array
    {
        return [];
    }
}
