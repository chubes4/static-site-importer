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
namespace BlockFormatBridge\Vendor\League\CommonMark\Extension\Footnote\Parser;

use BlockFormatBridge\Vendor\League\CommonMark\Extension\Footnote\Node\Footnote;
use BlockFormatBridge\Vendor\League\CommonMark\Node\Block\AbstractBlock;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Block\BlockContinue;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Block\BlockContinueParserInterface;
use BlockFormatBridge\Vendor\League\CommonMark\Parser\Cursor;
use BlockFormatBridge\Vendor\League\CommonMark\Reference\ReferenceInterface;
final class FootnoteParser extends AbstractBlockContinueParser
{
    /** @psalm-readonly */
    private Footnote $block;
    /** @psalm-readonly-allow-private-mutation */
    private ?int $indentation = null;
    public function __construct(ReferenceInterface $reference)
    {
        $this->block = new Footnote($reference);
    }
    public function getBlock(): Footnote
    {
        return $this->block;
    }
    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        if ($cursor->isBlank()) {
            return BlockContinue::at($cursor);
        }
        if ($cursor->isIndented()) {
            $this->indentation ??= $cursor->getIndent();
            $cursor->advanceBy($this->indentation, \true);
            return BlockContinue::at($cursor);
        }
        return BlockContinue::none();
    }
    public function isContainer(): bool
    {
        return \true;
    }
    public function canContain(AbstractBlock $childBlock): bool
    {
        return \true;
    }
}
