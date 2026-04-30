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
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\CommonMark\Parser\Inline;

use BlockFormatBridge\Vendor\League\CommonMark\Node\Inline\Text;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Inline\InlineParserInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Inline\InlineParserMatch;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\InlineParserContext;
use BlockFormatBridge\Vendor\League\CommonMark\Util\Html5EntityDecoder;
use BlockFormatBridge\Vendor\League\CommonMark\Util\RegexHelper;
final class EntityParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(RegexHelper::PARTIAL_ENTITY);
    }
    public function parse(InlineParserContext $inlineContext): bool
    {
        $entity = $inlineContext->getFullMatch();
        $inlineContext->getCursor()->advanceBy($inlineContext->getFullMatchLength());
        $inlineContext->getContainer()->appendChild(new Text(Html5EntityDecoder::decode($entity)));
        return \true;
    }
}
