<?php

declare (strict_types=1);
namespace BlockFormatBridge\Vendor\League\HTMLToMarkdown\Converter;

use BlockFormatBridge\Vendor\League\HTMLToMarkdown\ElementInterface;
class HorizontalRuleConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        return "---\n\n";
    }
    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['hr'];
    }
}
