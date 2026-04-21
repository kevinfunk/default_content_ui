<?php

namespace Drupal\Tests\default_content_ui_mapping\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\default_content_ui_mapping\Hook\MappingHooks;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the Default Content UI Mapping hooks.
 *
 * @group default_content_ui_mapping
 */
class MappingHooksTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'default_content_ui',
    'default_content_ui_mapping',
  ];

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The MappingHooks instance.
   *
   * @var \Drupal\default_content_ui_mapping\Hook\MappingHooks
   */
  protected $mappingHooks;

  /**
   * A temporary directory for testing.
   *
   * @var string
   */
  protected $tempDir;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['default_content_ui_mapping']);
    $this->fileSystem = $this->container->get('file_system');

    // Manually instantiate the hook class with injected services.
    $this->mappingHooks = new MappingHooks(
      $this->container->get('config.factory'),
      $this->fileSystem,
      $this->container->get('logger.factory')
    );

    // 1. Define the URI and prepare the root directory FIRST.
    $base_uri = 'temporary://test_import_' . $this->randomMachineName();
    $this->fileSystem->prepareDirectory($base_uri, FileSystemInterface::CREATE_DIRECTORY);

    // 2. Now it is safe to get the realpath.
    $this->tempDir = $this->fileSystem->realpath($base_uri);

    // 3. Assign the nested path to a variable so it can be passed by reference.
    $node_dir = $this->tempDir . '/content/node';
    $this->fileSystem->prepareDirectory($node_dir, FileSystemInterface::CREATE_DIRECTORY);
  }

  /**
   * Tests the pre-import hook logic.
   */
  public function testPreImportLogic() {
    // 1. Set up the active configuration for the submodule.
    $this->config('default_content_ui_mapping.settings')
      ->set('strip_translations', TRUE)
      ->set('fallback_langcode', 'en')
      ->set('mappings', [
        ['source' => 'media_image', 'target' => 'field_media_image'],
      ])
      ->set('excluded_fields', [
        ['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_deprecated'],
        // Rule that shouldn't match because the bundle is wrong:
        ['entity_type' => 'node', 'bundle' => 'page', 'field_name' => 'field_keep_me'],
      ])
      ->save();

    // 2. Create a dummy exported YAML file with English and Spanish data.
    $mock_data = [
      '_meta' => [
        'version' => '1.0',
        'entity_type' => 'node',
        'uuid' => '1234-5678-9012',
        'bundle' => 'article',
        'default_langcode' => 'en',
      ],
      'en' => [
        'title' => [['value' => 'English Title']],
        'media_image' => [['target_id' => 1]],
        'field_deprecated' => [['value' => 'Old data to strip']],
        'field_keep_me' => [['value' => 'Keep this data']],
        'content_translation_source' => [['value' => 'und']],
        'content_translation_outdated' => [['value' => 0]],
      ],
      'es' => [
        'title' => [['value' => 'Spanish Title']],
        'content_translation_source' => [['value' => 'en']],
      ],
    ];

    $file_path = $this->tempDir . '/content/node/1234-5678-9012.yml';
    file_put_contents($file_path, Yaml::encode($mock_data));

    // 3. Execute the hook, passing the temporary directory.
    $this->mappingHooks->preImport($this->tempDir);

    // 4. Decode the modified file and assert the changes.
    $modified_data = Yaml::decode(file_get_contents($file_path));

    // Assert Translations Stripped.
    $this->assertArrayNotHasKey('es', $modified_data, 'The Spanish translation should be completely stripped.');
    $this->assertArrayHasKey('en', $modified_data, 'The English fallback language should be retained.');
    $this->assertArrayHasKey('_meta', $modified_data, 'The _meta block should be retained.');

    // Assert Translation Metadata Stripped.
    $this->assertArrayNotHasKey('content_translation_source', $modified_data['en'], 'Translation source metadata should be stripped.');
    $this->assertArrayNotHasKey('content_translation_outdated', $modified_data['en'], 'Translation outdated metadata should be stripped.');

    // Assert Field Mapping.
    $this->assertArrayNotHasKey('media_image', $modified_data['en'], 'The original field name should be gone.');
    $this->assertArrayHasKey('field_media_image', $modified_data['en'], 'The target field name should be present.');
    $this->assertEquals(1, $modified_data['en']['field_media_image'][0]['target_id'], 'The mapped field data should be perfectly retained.');

    // Assert Exclusions.
    $this->assertArrayNotHasKey('field_deprecated', $modified_data['en'], 'The excluded field should be removed because it matches the bundle.');
    $this->assertArrayHasKey('field_keep_me', $modified_data['en'], 'The field should be kept because the exclusion rule was for the "page" bundle, not "article".');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Clean up the temporary directory.
    if ($this->tempDir) {
      $this->fileSystem->deleteRecursive($this->tempDir);
    }
    parent::tearDown();
  }

}
