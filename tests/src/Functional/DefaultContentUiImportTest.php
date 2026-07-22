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

    $zip->addFile($exported_file, 'content/node/' . $uuid . '.yml');
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

  /**
   * Tests that importing an existing UUID is skipped, not overwritten.
   *
   * ImportBatch::import() always calls Existing::Skip — but every other
   * test here deletes the node before importing (a fresh-UUID scenario),
   * so this skip-vs-overwrite semantic, the realistic resync/re-import
   * use case, has never actually been exercised. A regression that
   * flipped skip to overwrite would change real semantics with nothing
   * to catch it.
   */
  public function testImportSkipsExistingUuidInsteadOfOverwriting() {
    $admin_user = $this->drupalCreateUser([
      'default content import',
      'access administration pages',
    ]);
    $this->drupalLogin($admin_user);

    // 1. Create and export a node.
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Original Title',
    ]);
    $uuid = $node->uuid();

    /** @var \Drupal\Core\DefaultContent\Exporter $exporter */
    $exporter = \Drupal::service(Exporter::class);
    $file_system = \Drupal::service('file_system');
    $export_dir = $file_system->realpath('temporary://test_export_source_existing');
    $file_system->mkdir($export_dir);
    $exporter->exportToFile($node, $export_dir);

    // 2. Change the live entity's title without re-exporting, so the
    // archive now represents a stale version of this same entity.
    $node->setTitle('Updated Title')->save();

    // 3. Build the ZIP from the stale export.
    $zip_path = $file_system->realpath('temporary://test_import_existing.zip');
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $exported_file = $export_dir . '/node/' . $uuid . '.yml';
    $this->assertFileExists($exported_file);
    $zip->addFile($exported_file, 'content/node/' . $uuid . '.yml');
    $zip->close();

    // 4. Import it.
    $this->drupalGet('/admin/config/development/default-content/import');
    $this->submitForm(['files[archive]' => $zip_path], 'Import Content');
    $this->assertSession()->pageTextContains('Content import completed successfully.');

    // 5. The live entity must still have the updated title — the stale
    // archive must have been skipped, not used to overwrite it.
    $reloaded = \Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid);
    $this->assertEquals('Updated Title', $reloaded->getTitle(), 'Existing::Skip must leave the current entity untouched.');
  }

  /**
   * Tests that an archive with too many entries is rejected.
   */
  public function testImportRejectsTooManyFiles() {
    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    $file_system = \Drupal::service('file_system');
    $zip_path = $file_system->realpath('temporary://test_too_many_files.zip');
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    for ($i = 0; $i <= 5000; $i++) {
      $zip->addFromString("content/node/file_$i.yml", '');
    }
    $zip->close();

    $this->drupalGet('/admin/config/development/default-content/import');
    $this->submitForm(['files[archive]' => $zip_path], 'Import Content');

    $this->assertSession()->pageTextContains('too many entries');
  }

  /**
   * Tests that an archive with an excessive uncompressed size is rejected.
   *
   * A small, highly compressible upload can still decompress to gigabytes,
   * so the guard checks the *uncompressed* size recorded in the archive,
   * independent of the (already size-limited) compressed upload itself.
   */
  public function testImportRejectsExcessiveUncompressedSize() {
    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    $file_system = \Drupal::service('file_system');
    $zip_path = $file_system->realpath('temporary://test_zip_bomb.zip');
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    // Highly repetitive content compresses down to almost nothing, keeping
    // the actual upload tiny while the archive's recorded uncompressed size
    // still exceeds the 200MB cap.
    $zip->addFromString('content/node/bomb.yml', str_repeat('0', 201 * 1024 * 1024));
    $zip->close();

    $this->drupalGet('/admin/config/development/default-content/import');
    $this->submitForm(['files[archive]' => $zip_path], 'Import Content');

    $this->assertSession()->pageTextContains('too large');
  }

}
