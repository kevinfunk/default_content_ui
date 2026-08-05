<?php

namespace Drupal\Tests\default_content_ui_mapping\Kernel;

use Drupal\Core\DefaultContent\PreEntityImportEvent;
use Drupal\default_content_ui_mapping\EventSubscriber\MappingSubscriber;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the Default Content UI Mapping event subscriber.
 *
 * @group default_content_ui_mapping
 */
class MappingSubscriberTest extends KernelTestBase {

  /**
   * Modules to enable.
   *
   * @var array<string>
   */
  protected static $modules = [
    'system',
    'default_content_ui',
    'default_content_ui_mapping',
  ];

  /**
   * The subscriber under test.
   *
   * @var \Drupal\default_content_ui_mapping\EventSubscriber\MappingSubscriber
   */
  protected $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['default_content_ui_mapping']);

    $this->subscriber = new MappingSubscriber(
      $this->container->get('config.factory'),
      $this->container->get('logger.factory')
    );
  }

  /**
   * Tests the pre-entity-import event logic.
   */
  public function testPreEntityImportLogic() {
    // 1. Set up the active configuration for the submodule.
    $this->config('default_content_ui_mapping.settings')
      ->set('strip_translations', TRUE)
      ->set('fallback_langcode', 'fr')
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

    // 2. Build the in-memory data for a Node import, matching the real
    // shape produced by \Drupal\Core\DefaultContent\Exporter: the default
    // translation's field values live under 'default', and every other
    // translation lives under 'translations.<langcode>' — never as bare
    // top-level langcode keys.
    $node_data = [
      '_meta' => [
        'version' => '1.0',
        'entity_type' => 'node',
        'uuid' => '1234-5678-9012',
        'bundle' => 'article',
        'default_langcode' => 'en',
      ],
      'default' => [
        'title' => [['value' => 'English Title']],
        'media_image' => [['target_id' => 1]],
        'field_deprecated' => [['value' => 'Old data to strip']],
        'field_keep_me' => [['value' => 'Keep this data']],
        'content_translation_source' => [['value' => 'und']],
        'content_translation_outdated' => [['value' => 0]],
      ],
      'translations' => [
        // 'es' is not the configured fallback_langcode ('fr'), so it must
        // be stripped entirely.
        'es' => [
          'title' => [['value' => 'Spanish Title']],
          'content_translation_source' => [['value' => 'en']],
        ],
        // 'fr' is the configured fallback_langcode, so it must be kept —
        // and mapping/exclusion/meta-stripping must still apply to it,
        // not just to 'default'.
        'fr' => [
          'title' => [['value' => 'French Title']],
          'field_deprecated' => [['value' => 'Old data to strip']],
          'content_translation_source' => [['value' => 'en']],
        ],
      ],
    ];

    $node_event = new PreEntityImportEvent($node_data);
    $this->subscriber->onPreEntityImport($node_event);
    $modified_node_data = $node_event->data;

    $this->assertArrayNotHasKey('es', $modified_node_data['translations'] ?? [], 'The non-fallback translation should be stripped.');
    $this->assertArrayHasKey('fr', $modified_node_data['translations'], 'The fallback translation should be kept.');

    $this->assertArrayNotHasKey('content_translation_source', $modified_node_data['default'], 'Translation metadata should be stripped from the default translation.');
    $this->assertArrayHasKey('field_media_image', $modified_node_data['default'], 'The field should be mapped in the default translation.');
    $this->assertArrayNotHasKey('field_deprecated', $modified_node_data['default'], 'Entire field exclusion should work on the default translation.');

    $this->assertArrayNotHasKey('content_translation_source', $modified_node_data['translations']['fr'], 'Translation metadata should also be stripped from the kept translation.');
    $this->assertArrayNotHasKey('field_deprecated', $modified_node_data['translations']['fr'], 'Field exclusion should also apply to the kept translation, not just the default one.');

    // 3. Build the in-memory data for a User import.
    // Testing bundleless behavior and value exclusions.
    $user_data = [
      '_meta' => [
        'version' => '1.0',
        'entity_type' => 'user',
        'uuid' => 'adab4c9b-b069-4487-901c-d92c0565c87d',
        'default_langcode' => 'en',
        // Intentionally omitting 'bundle' to test our fallback logic.
      ],
      'default' => [
        'name' => [['value' => 'admin@example.com']],
        'roles' => [
          ['target_id' => 'member'],
          ['target_id' => 'administrator'],
        ],
      ],
    ];

    $user_event = new PreEntityImportEvent($user_data);
    $this->subscriber->onPreEntityImport($user_event);
    $modified_user_data = $user_event->data;

    $roles = $modified_user_data['default']['roles'];

    $this->assertCount(1, $roles, 'Only one role should remain in the array.');
    $this->assertEquals('administrator', $roles[0]['target_id'], 'The administrator role should be kept.');
    $this->assertArrayNotHasKey(1, $roles, 'The array should be perfectly re-indexed (starting at 0).');
  }

  /**
   * Tests that the subscriber is a no-op when nothing is configured.
   */
  public function testNoConfigurationIsNoOp() {
    $data = [
      '_meta' => [
        'entity_type' => 'node',
        'uuid' => '1234-5678-9012',
        'bundle' => 'article',
        'default_langcode' => 'en',
      ],
      'default' => [
        'title' => [['value' => 'Unchanged Title']],
      ],
    ];

    $event = new PreEntityImportEvent($data);
    $this->subscriber->onPreEntityImport($event);

    $expected = $data;
    unset($expected['_meta']);
    $this->assertEquals($expected, $event->data);
  }

  /**
   * Tests the subscriber is registered on the real event dispatcher.
   */
  public function testSubscriberIsRegistered() {
    $this->assertArrayHasKey(
      PreEntityImportEvent::class,
      MappingSubscriber::getSubscribedEvents(),
      'The subscriber must declare PreEntityImportEvent in getSubscribedEvents().'
    );
  }

}
