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
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\TableOfContents;

use BlockFormatBridge\Vendor\League\CommonMark\Node\Node;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
final class TableOfContentsRenderer implements NodeRendererInterface, XmlNodeRendererInterface
{
    /** @var NodeRendererInterface&XmlNodeRendererInterface */
    private $innerRenderer;
    /**
     * @psalm-param NodeRendererInterface&XmlNodeRendererInterface $innerRenderer
     *
     * @phpstan-param NodeRendererInterface&XmlNodeRendererInterface $innerRenderer
     */
    public function __construct(NodeRendererInterface $innerRenderer)
    {
        $this->innerRenderer = $innerRenderer;
    }
    /**
     * {@inheritDoc}
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        return $this->innerRenderer->render($node, $childRenderer);
    }
    public function getXmlTagName(Node $node): string
    {
        return 'table_of_contents';
    }
    /**
     * @return array<string, scalar>
     */
    public function getXmlAttributes(Node $node): array
    {
        return $this->innerRenderer->getXmlAttributes($node);
    }
}
