<?php

declare (strict_types=1);
namespace BlockFormatBridge\Vendor\League\HTMLToMarkdown\Converter;

use BlockFormatBridge\Vendor\League\HTMLToMarkdown\Configuration;
use BlockFormatBridge\Vendor\League\HTMLToMarkdown\ConfigurationAwareInterface;
use BlockFormatBridge\Vendor\League\HTMLToMarkdown\ElementInterface;
class HardBreakConverter implements ConverterInterface, ConfigurationAwareInterface
{
    /** @var Configuration */
    protected $config;
    public function setConfig(Configuration $config): void
    {
        $this->config = $config;
    }
    public function convert(ElementInterface $element): string
    {
        $return = $this->config->getOption('hard_break') ? "\n" : "  \n";
        $next = $element->getNext();
        if ($next) {
            $nextValue = $next->getValue();
            if ($nextValue) {
                if (\in_array(\substr($nextValue, 0, 2), ['- ', '* ', '+ '], \true)) {
                    $parent = $element->getParent();
                    if ($parent && $parent->getTagName() === 'li') {
                        $return .= '\\';
                    }
                }
            }
        }
        return $return;
    }
    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['br'];
    }
}
