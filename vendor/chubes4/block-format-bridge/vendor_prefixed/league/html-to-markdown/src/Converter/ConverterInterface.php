<?php

declare (strict_types=1);
namespace BlockFormatBridge\Vendor\League\HTMLToMarkdown\Converter;

use BlockFormatBridge\Vendor\League\HTMLToMarkdown\ElementInterface;
interface ConverterInterface
{
    public function convert(ElementInterface $element): string;
    /**
     * @return string[]
     */
    public function getSupportedTags(): array;
}
