<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\DefaultContent\Exporter;
use Drupal\entity_composite_relationship_test\Entity\EntityTestCompositeRelationship;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests exporting an entity_reference_revisions field via core's Exporter.
 *
 * Without DefaultContentSubscriber's registration of an export callback
 * for this field type, exporting a node with an entity_reference_revisions
 * field produces raw, non-portable target_id/target_revision_id values,
 * and no recorded
 * dependency. This exercises the base 'entity_reference_revisions' field
 * type itself (not a specific module's subclass of it, like Cohesion's),
 * to prove the discovery mechanism isn't hardcoded to one field type.
 *
 * @group default_content_ui
 */
class DefaultContentSubscriberTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'user',
    'system',
    'field',
    'entity_reference_revisions',
    'entity_test',
    'entity_composite_relationship_test',
    'default_content_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test_composite');
    $this->installSchema('node', ['node_access']);

    FieldStorageConfig::create([
      'field_name' => 'field_composite_reference',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'entity_test_composite'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_composite_reference',
      'entity_type' => 'node',
      'bundle' => 'article',
    ])->save();
  }

  /**
   * Tests that the reference exports as a UUID, with a recorded dependency.
   */
  public function testEntityReferenceRevisionsFieldExportsAsUuid() {
    $referenced = EntityTestCompositeRelationship::create(['name' => 'Referenced entity']);
    $referenced->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Host node',
      'field_composite_reference' => $referenced,
    ]);
    $node->save();

    $result = \Drupal::service(Exporter::class)->export($node);

    $exported_value = $result->data['default']['field_composite_reference'][0] ?? NULL;
    $this->assertSame(['entity' => $referenced->uuid()], $exported_value);

    // ExportMetadata::getDependencies() returns a plain list of
    // [entity_type_id, uuid] tuples; get()['depends'] is the UUID-keyed
    // shape that actually ends up in the exported _meta.depends YAML.
    $this->assertSame(
      [$referenced->uuid() => 'entity_test_composite'],
      $result->metadata->get()['depends'] ?? NULL
    );
  }

  /**
   * Tests that a dangling reference (entity deleted) is skipped, not fatal.
   */
  public function testDanglingReferenceIsSkipped() {
    $referenced = EntityTestCompositeRelationship::create(['name' => 'Referenced entity']);
    $referenced->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Host node',
      'field_composite_reference' => $referenced,
    ]);
    $node->save();
    $node_id = $node->id();

    $referenced->delete();

    // Reload from storage so the field resolves the reference fresh,
    // rather than reusing the already-loaded (and now stale) entity kept
    // in memory on $node.
    \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node_id]);
    $node = Node::load($node_id);

    $result = \Drupal::service(Exporter::class)->export($node);

    $this->assertArrayNotHasKey('field_composite_reference', $result->data['default']);
  }

  /**
   * Tests that host-tracking fields are excluded from the child's export.
   *
   * The entity_test_composite entity type (like Cohesion's
   * cohesion_layout) declares parent_id/parent_type/parent_field_name as
   * its entity_revision_parent_*_field annotations. These store a raw,
   * site-specific numeric host ID — exporting them as-is would make the
   * imported entity "hosted" by whatever unrelated entity happens to
   * have that numeric ID on the destination site.
   */
  public function testHostTrackingFieldsAreExcludedFromExport() {
    $referenced = EntityTestCompositeRelationship::create(['name' => 'Referenced entity']);
    $referenced->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Host node',
      'field_composite_reference' => $referenced,
    ]);
    $node->save();

    // Saving the host node above triggers
    // EntityReferenceRevisionsItem::postSave(), which populates
    // parent_id/parent_type on the referenced entity to point back at
    // its host — reload to see the real, now-populated values.
    $storage = \Drupal::entityTypeManager()->getStorage('entity_test_composite');
    $storage->resetCache([$referenced->id()]);
    $referenced = $storage->load($referenced->id());
    $this->assertEquals($node->id(), $referenced->get('parent_id')->value);
    $this->assertSame('node', $referenced->get('parent_type')->value);

    $result = \Drupal::service(Exporter::class)->export($referenced);

    $this->assertArrayNotHasKey('parent_id', $result->data['default']);
    $this->assertArrayNotHasKey('parent_type', $result->data['default']);
    $this->assertArrayNotHasKey('parent_field_name', $result->data['default']);
  }

}
