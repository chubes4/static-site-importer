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
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList;

use BlockFormatBridge\Vendor\League\CommonMark\Environment\EnvironmentBuilderInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Event\DocumentParsedEvent;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Event\ConsecutiveDescriptionListMerger;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Event\LooseDescriptionHandler;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Node\Description;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionList;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Node\DescriptionTerm;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Parser\DescriptionStartParser;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Renderer\DescriptionListRenderer;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Renderer\DescriptionRenderer;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\DescriptionList\Renderer\DescriptionTermRenderer;
use BlockFormatBridge\Vendor\League\CommonMark\Extension\ExtensionInterface;
final class DescriptionListExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addBlockStartParser(new DescriptionStartParser());
        $environment->addEventListener(DocumentParsedEvent::class, new LooseDescriptionHandler(), 1001);
        $environment->addEventListener(DocumentParsedEvent::class, new ConsecutiveDescriptionListMerger(), 1000);
        $environment->addRenderer(DescriptionList::class, new DescriptionListRenderer());
        $environment->addRenderer(DescriptionTerm::class, new DescriptionTermRenderer());
        $environment->addRenderer(Description::class, new DescriptionRenderer());
    }
}
