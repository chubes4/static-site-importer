<?php

namespace BlockFormatBridge\Vendor;

/**
 * Generate a normalized inventory of WordPress core block metadata.
 *
 * Usage:
 *   php tools/generate-core-block-inventory.php /path/to/wp-includes/blocks
 */
// phpcs:disable
if (!\function_exists('BlockFormatBridge\Vendor\html_to_blocks_generate_core_block_inventory')) {
    /**
     * Generates the core block inventory from a WordPress blocks directory.
     *
     * @param string $blocks_dir Path to wp-includes/blocks.
     * @return array{generated_from:string,block_count:int,blocks:array<int,array<string,mixed>>}
     */
    function html_to_blocks_generate_core_block_inventory(string $blocks_dir): array
    {
        $blocks_dir = \rtrim($blocks_dir, \DIRECTORY_SEPARATOR);
        $files = \glob($blocks_dir . '/*/block.json');
        if (!\is_array($files) || empty($files)) {
            throw new \RuntimeException("No block.json files found under {$blocks_dir}");
        }
        $blocks = [];
        foreach ($files as $file) {
            $raw = \file_get_contents($file);
            $data = \is_string($raw) ? \json_decode($raw, \true) : null;
            if (!\is_array($data) || empty($data['name'])) {
                throw new \RuntimeException("Invalid block metadata: {$file}");
            }
            $blocks[$data['name']] = ['name' => $data['name'], 'category' => $data['category'] ?? null, 'attributes' => \array_values(\array_keys((array) ($data['attributes'] ?? []))), 'supports' => \array_values(\array_keys((array) ($data['supports'] ?? []))), 'allowedBlocks' => $data['allowedBlocks'] ?? [], 'parent' => $data['parent'] ?? [], 'ancestor' => $data['ancestor'] ?? [], 'usesContext' => $data['usesContext'] ?? [], 'providesContext' => \array_values(\array_keys((array) ($data['providesContext'] ?? []))), 'selectors' => \array_values(\array_keys((array) ($data['selectors'] ?? [])))];
        }
        \ksort($blocks);
        return ['generated_from' => 'wp-includes/blocks/*/block.json', 'block_count' => \count($blocks), 'blocks' => \array_values($blocks)];
    }
}
if (\PHP_SAPI === 'cli' && \realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $blocks_dir = $argv[1] ?? '';
    if ($blocks_dir === '' || !\is_dir($blocks_dir)) {
        \fwrite(\STDERR, "Usage: php tools/generate-core-block-inventory.php /path/to/wp-includes/blocks\n");
        exit(1);
    }
    echo \json_encode(html_to_blocks_generate_core_block_inventory($blocks_dir), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . \PHP_EOL;
}
