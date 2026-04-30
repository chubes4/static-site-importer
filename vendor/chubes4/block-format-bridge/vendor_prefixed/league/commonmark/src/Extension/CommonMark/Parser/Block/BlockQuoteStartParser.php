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
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\CommonMark\Parser\Block;

use BlockFormatBridge\Vendor\League\CommonMark\Parser\Block\BlockStart;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Block\BlockStartParserInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Cursor;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\MarkdownParserStateInterface;
final class BlockQuoteStartParser implements BlockStartParserInterface
{
    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
    {
        if ($cursor->isIndented()) {
            return BlockStart::none();
        }
        if ($cursor->getNextNonSpaceCharacter() !== '>') {
            return BlockStart::none();
        }
        $cursor->advanceToNextNonSpaceOrTab();
        $cursor->advanceBy(1);
        $cursor->advanceBySpaceOrTab();
        return BlockStart::of(new BlockQuoteParser())->at($cursor);
    }
}
