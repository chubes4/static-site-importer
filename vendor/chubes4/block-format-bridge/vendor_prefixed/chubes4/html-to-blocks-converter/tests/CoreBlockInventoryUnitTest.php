<?php

namespace BlockFormatBridge\Vendor;

/**
 * Core block inventory and classification coverage gate.
 *
 * @package HtmlToBlocksConverter
 */
/**
 * Verifies the generated core block inventory has an explicit classification.
 */
class CoreBlockInventoryUnitTest extends WP_UnitTestCase
{
    /**
     * Loads the inventory generator.
     */
    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        require_once \dirname(__DIR__) . '/tools/generate-core-block-inventory.php';
    }
    /**
     * Every inventory block must have one valid classification, and stale entries
     * must be intentionally marked historical.
     */
    public function test_inventory_and_classification_are_aligned(): void
    {
        $inventory = html_to_blocks_generate_core_block_inventory(\ABSPATH . \WPINC . '/blocks');
        $classification = $this->read_json(\dirname(__DIR__) . '/docs/core-block-classification.json');
        $inventory_names = array();
        foreach ($inventory['blocks'] as $block) {
            $this->assertIsString($block['name'] ?? null);
            $this->assertStringStartsWith('core/', $block['name']);
            foreach (array('category', 'attributes', 'supports', 'allowedBlocks', 'usesContext', 'providesContext', 'selectors') as $field) {
                $this->assertArrayHasKey($field, $block, $block['name'] . ' should expose ' . $field . ' metadata.');
            }
            $inventory_names[$block['name']] = \true;
        }
        $this->assertSame($inventory['block_count'], \count($inventory_names));
        $this->assertGreaterThan(50, \count($inventory_names), 'Inventory should represent the core block catalog.');
        $valid_buckets = \array_fill_keys(\array_keys($classification['buckets']), \true);
        $classifications = $classification['classifications'];
        foreach (\array_keys($inventory_names) as $block_name) {
            $this->assertArrayHasKey($block_name, $classifications, $block_name . ' must be classified.');
        }
        foreach ($classifications as $block_name => $entry) {
            $this->assertTrue(isset($inventory_names[$block_name]) || !empty($entry['historical']) || !empty($entry['introduced_after']), $block_name . ' is not in the inventory and is not marked historical or forward-compatible.');
            $this->assertArrayHasKey($entry['bucket'] ?? '', $valid_buckets, $block_name . ' must use a valid bucket.');
            $this->assertIsString($entry['rationale'] ?? null, $block_name . ' should explain its classification.');
            $this->assertNotSame('', \trim($entry['rationale'] ?? ''), $block_name . ' should explain its classification.');
        }
    }
    /**
     * Raw-transformable and explicit-marker classifications should map back to
     * executable source; context/dynamic buckets must not accidentally register raw
     * transforms.
     */
    public function test_classification_matches_registered_transform_surface(): void
    {
        $classification = $this->read_json(\dirname(__DIR__) . '/docs/core-block-classification.json');
        $registry_source = \file_get_contents(\dirname(__DIR__) . '/includes/class-transform-registry.php');
        $raw_source = \file_get_contents(\dirname(__DIR__) . '/raw-handler.php');
        $this->assertIsString($registry_source);
        $this->assertIsString($raw_source);
        $raw_transform_blocks = array();
        \preg_match_all("/'blockName'\\s*=>\\s*'([^']+)'/", $registry_source, $matches);
        foreach ($matches[1] as $block_name) {
            $raw_transform_blocks[$block_name] = \true;
        }
        $generated_blocks = array();
        \preg_match_all("/create_block\\(\\s*'([^']+)'/", $registry_source . "\n" . $raw_source, $matches);
        foreach ($matches[1] as $block_name) {
            $generated_blocks[$block_name] = \true;
        }
        foreach ($classification['classifications'] as $block_name => $entry) {
            if ('raw-transformable' === $entry['bucket'] || 'explicit-marker' === $entry['bucket']) {
                $this->assertTrue(isset($raw_transform_blocks[$block_name]) || isset($generated_blocks[$block_name]), $block_name . ' should map to a registered transform or generated block.');
            }
            if (\in_array($entry['bucket'], array('compiler-only', 'dynamic-unsupported'), \true)) {
                $this->assertArrayNotHasKey($block_name, $raw_transform_blocks, $block_name . ' should not be a raw transform.');
            }
        }
    }
    /**
     * Reads a JSON fixture.
     *
     * @param string $path Fixture path.
     * @return array<string,mixed>
     */
    private function read_json(string $path): array
    {
        $raw = \file_get_contents($path);
        $this->assertIsString($raw, 'Unable to read ' . $path);
        $data = \json_decode($raw, \true);
        $this->assertIsArray($data, 'Invalid JSON in ' . $path);
        return $data;
    }
}
