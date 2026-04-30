<?php

/*
 * This file is part of the league/commonmark package.
 *
 * (c) Colin O'Dell <colinodell@gmail.com>
 * (c) Rezo Zero / Ambroise Maupate
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare (strict_types=1);
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\Footnote\Renderer;

use BlockFormatBridge\Vendor\League\CommonMark\Extension\Footnote\Node\FootnoteBackref;
use BlockFormatBridge\Vendor\League\CommonMark\Node\Node;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Util\HtmlElement;
use BlockFormatBridge\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use BlockFormatBridge\Vendor\League\Config\ConfigurationAwareInterface;
use BlockFormatBridge\Vendor\League\Config\ConfigurationInterface;
final class FootnoteBackrefRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{
    public const DEFAULT_SYMBOL = '↩';
    private ConfigurationInterface $config;
    /**
     * @param FootnoteBackref $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        FootnoteBackref::assertInstanceOf($node);
        $attrs = $node->data->getData('attributes');
        $attrs->append('class', $this->config->get('footnote/backref_class'));
        $attrs->set('rev', 'footnote');
        $attrs->set('href', \mb_strtolower($node->getReference()->getDestination(), 'UTF-8'));
        $attrs->set('role', 'doc-backlink');
        $symbol = $this->config->get('footnote/backref_symbol');
        \assert(\is_string($symbol));
        return '&nbsp;' . new HtmlElement('a', $attrs->export(), \htmlspecialchars($symbol), \true);
    }
    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }
    public function getXmlTagName(Node $node): string
    {
        return 'footnote_backref';
    }
    /**
     * @param FootnoteBackref $node
     *
     * @return array<string, scalar>
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function getXmlAttributes(Node $node): array
    {
        FootnoteBackref::assertInstanceOf($node);
        return ['reference' => $node->getReference()->getLabel()];
    }
}
