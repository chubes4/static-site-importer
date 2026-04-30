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

use BlockFormatBridge\Vendor\League\CommonMark\Extension\Footnote\Node\Footnote;
use BlockFormatBridge\Vendor\League\CommonMark\Node\Node;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\ChildNodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Renderer\NodeRendererInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Util\HtmlElement;
use BlockFormatBridge\Vendor\League\CommonMark\Xml\XmlNodeRendererInterface;
use BlockFormatBridge\Vendor\League\Config\ConfigurationAwareInterface;
use BlockFormatBridge\Vendor\League\Config\ConfigurationInterface;
final class FootnoteRenderer implements NodeRendererInterface, XmlNodeRendererInterface, ConfigurationAwareInterface
{
    private ConfigurationInterface $config;
    /**
     * @param Footnote $node
     *
     * {@inheritDoc}
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        Footnote::assertInstanceOf($node);
        $attrs = $node->data->getData('attributes');
        $attrs->append('class', $this->config->get('footnote/footnote_class'));
        $attrs->set('id', $this->config->get('footnote/footnote_id_prefix') . \mb_strtolower($node->getReference()->getLabel(), 'UTF-8'));
        $attrs->set('role', 'doc-endnote');
        return new HtmlElement('li', $attrs->export(), $childRenderer->renderNodes($node->children()), \true);
    }
    public function setConfiguration(ConfigurationInterface $configuration): void
    {
        $this->config = $configuration;
    }
    public function getXmlTagName(Node $node): string
    {
        return 'footnote';
    }
    /**
     * @param Footnote $node
     *
     * @return array<string, scalar>
     *
     * @psalm-suppress MoreSpecificImplementedParamType
     */
    public function getXmlAttributes(Node $node): array
    {
        Footnote::assertInstanceOf($node);
        return ['reference' => $node->getReference()->getLabel()];
    }
}
