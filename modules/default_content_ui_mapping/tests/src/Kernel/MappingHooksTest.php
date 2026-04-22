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

    // 3. Prepare the nested node directory.
    $node_dir = $this->tempDir . '/content/node';
    $this->fileSystem->prepareDirectory($node_dir, FileSystemInterface::CREATE_DIRECTORY);

    // 4. Prepare the nested user directory.
    $user_dir = $this->tempDir . '/content/user';
    $this->fileSystem->prepareDirectory($user_dir, FileSystemInterface::CREATE_DIRECTORY);
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
        [
          'entity_type' => 'node',
          'bundle' => 'article',
          'field_name' => 'field_deprecated',
        ],
        [
          'entity_type' => 'node',
          'bundle' => 'page',
          'field_name' => 'field_keep_me',
        ],
      ])
      ->set('value_exclusions', [
        [
          'entity_type' => 'user',
          'bundle' => '',
          'field_name' => 'roles',
          'property' => 'target_id',
          'value' => 'member',
        ],
      ])
      ->save();

    // 2. Create a dummy exported Node YAML file.
    $node_mock_data = [
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

    $node_file_path = $this->tempDir . '/content/node/1234-5678-9012.yml';
    file_put_contents($node_file_path, Yaml::encode($node_mock_data));

    // 3. Create a dummy exported User YAML file.
    // Testing bundleless behavior and value exclusions.
    $user_mock_data = [
      '_meta' => [
        'version' => '1.0',
        'entity_type' => 'user',
        'uuid' => 'adab4c9b-b069-4487-901c-d92c0565c87d',
        'default_langcode' => 'en',
        // Intentionally omitting 'bundle' to test our new fallback logic.
      ],
      'en' => [
        'name' => [['value' => 'admin@example.com']],
        'roles' => [
          ['target_id' => 'member'],
          ['target_id' => 'administrator'],
        ],
      ],
    ];

    $user_file_path = $this->tempDir . '/content/user/adab4c9b-b069-4487-901c-d92c0565c87d.yml';
    file_put_contents($user_file_path, Yaml::encode($user_mock_data));

    // 4. Execute the hook, passing the temporary directory.
    $this->mappingHooks->preImport($this->tempDir);

    // 5. Decode the modified Node file and assert changes.
    $modified_node_data = Yaml::decode(file_get_contents($node_file_path));

    $this->assertArrayNotHasKey('es', $modified_node_data, 'Translations should be stripped.');
    $this->assertArrayNotHasKey('content_translation_source', $modified_node_data['en'], 'Translation metadata should be stripped.');
    $this->assertArrayHasKey('field_media_image', $modified_node_data['en'], 'The field should be mapped.');
    $this->assertArrayNotHasKey('field_deprecated', $modified_node_data['en'], 'Entire field exclusion should work.');

    // 6. Decode the modified User file and assert the Value Exclusion changes.
    $modified_user_data = Yaml::decode(file_get_contents($user_file_path));
    $roles = $modified_user_data['en']['roles'];

    $this->assertCount(1, $roles, 'Only one role should remain in the array.');
    $this->assertEquals('administrator', $roles[0]['target_id'], 'The administrator role should be kept.');
    $this->assertArrayNotHasKey(1, $roles, 'The array should be perfectly re-indexed (starting at 0).');
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
