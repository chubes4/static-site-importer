<?php

declare (strict_types=1);
namespace BlockFormatBridge\Vendor\League\HTMLToMarkdown;

interface PreConverterInterface
{
    public function preConvert(ElementInterface $element): void;
}
