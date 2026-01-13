<?php

namespace Drupal\Tests\default_content_ui\Functional;

use Drupal\Core\DefaultContent\Exporter;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Default Content UI import features.
 *
 * @group default_content_ui
 */
class DefaultContentUiImportTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var string[]
   */
  protected static $modules = [
    'default_content_ui',
    'serialization',
    'node',
    'user',
    'file',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
  }

  /**
   * Tests importing content from a ZIP archive.
   */
  public function testImportContent() {
    $admin_user = $this->drupalCreateUser([
      'default content import',
      'access administration pages',
    ]);
    $this->drupalLogin($admin_user);

    // 1. Create a real node to test with.
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Imported Test Node',
    ]);
    $uuid = $node->uuid();

    // 2. Export it using the Core Exporter to ensure valid YAML format.
    /** @var \Drupal\Core\DefaultContent\Exporter $exporter */
    $exporter = \Drupal::service(Exporter::class);

    // Create a temporary directory for the export source.
    $file_system = \Drupal::service('file_system');
    $export_dir = $file_system->realpath('temporary://test_export_source_' . time());
    $file_system->mkdir($export_dir);

    // Export the node to the temporary directory.
    // This creates the file at: $export_dir/node/$uuid.yml.
    $exporter->exportToFile($node, $export_dir);

    // 3. Delete the node so we can prove the import actually restores it.
    $node->delete();
    $this->assertNull(\Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid), 'Node successfully deleted before import.');

    // 4. Create the ZIP archive from the exported file.
    $zip_path = $file_system->realpath('temporary://test_import.zip');
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

    // The exporter creates {entity_type}/{uuid}.yml structure.
    // We add it to the zip with that same relative path.
    $exported_file = $export_dir . '/node/' . $uuid . '.yml';
    $this->assertFileExists($exported_file);

    $zip->addFile($exported_file, 'node/' . $uuid . '.yml');
    $zip->close();

    // 5. Navigate to Import form and upload the ZIP.
    $this->drupalGet('/admin/config/development/default-content/import');
    $this->assertSession()->statusCodeEquals(200);

    $edit = [
      'files[archive]' => $zip_path,
    ];
    $this->submitForm($edit, 'Import Content');

    // 6. Verify Success Message.
    $this->assertSession()->pageTextContains('Content import completed successfully.');

    // 7. Verify Node exists in database.
    $imported_node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid);
    $this->assertNotNull($imported_node, 'Imported node should exist in the database.');
    $this->assertEquals('Imported Test Node', $imported_node->getTitle());
  }

}
