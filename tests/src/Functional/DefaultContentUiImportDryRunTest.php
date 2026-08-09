<?php

namespace Drupal\Tests\default_content_ui\Functional;

use Drupal\Core\DefaultContent\Exporter;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the dry-run report shown before content is actually imported.
 *
 * @group default_content_ui
 */
class DefaultContentUiImportDryRunTest extends BrowserTestBase {

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
   * Builds a ZIP archive containing one exported node.
   *
   * @return array
   *   The node's UUID and the real path of the built ZIP archive.
   */
  protected function buildArchiveWithNode(string $title): array {
    $node = $this->drupalCreateNode(['type' => 'page', 'title' => $title]);
    $uuid = $node->uuid();

    /** @var \Drupal\Core\DefaultContent\Exporter $exporter */
    $exporter = \Drupal::service(Exporter::class);
    $file_system = \Drupal::service('file_system');
    $export_dir = $file_system->realpath('temporary://test_export_source_' . $this->randomMachineName());
    $file_system->mkdir($export_dir);
    $exporter->exportToFile($node, $export_dir);
    $node->delete();

    $zip_path = $file_system->realpath('temporary://' . $this->randomMachineName() . '.zip');
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $zip->addFile($export_dir . '/node/' . $uuid . '.yml', 'content/node/' . $uuid . '.yml');
    $zip->close();

    return [$uuid, $zip_path];
  }

  /**
   * Tests that a clean archive imports immediately, with no report page.
   *
   * There's nothing to review when the scan finds no issues, so the scan
   * happens transparently and the import completes in one click, exactly
   * like the "skip dry run" setting — the report page only appears when
   * there's actually something on it to look at.
   */
  public function testCleanArchiveImportsImmediately() {
    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    [$uuid, $zip_path] = $this->buildArchiveWithNode('Dry Run Node');

    $this->drupalGet('/admin/config/development/default-content/import');
    $this->submitForm(['files[archive]' => $zip_path], 'Import Content');

    $this->assertSession()->addressEquals('/admin/config/development/default-content/import');
    $this->assertSession()->pageTextContains('Content import completed successfully.');
    $this->assertNotNull(\Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid));
  }

  /**
   * Builds a ZIP archive containing one node referencing an unknown field.
   *
   * @return string
   *   The node's UUID.
   */
  protected function buildArchiveWithUnknownField(): string {
    $file_system = \Drupal::service('file_system');
    $zip_path = $file_system->realpath('temporary://' . $this->randomMachineName() . '.zip');
    $zip = new \ZipArchive();
    $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $uuid = '22222222-2222-2222-2222-222222222222';
    $zip->addFromString('content/node/' . $uuid . '.yml', implode("\n", [
      '_meta:',
      '  entity_type: node',
      '  bundle: page',
      "  uuid: '$uuid'",
      '  default_langcode: en',
      'default:',
      '  title:',
      '    - value: \'Report Node\'',
      '  field_does_not_exist:',
      '    - value: \'orphaned data\'',
    ]));
    $zip->close();

    $this->drupalGet('/admin/config/development/default-content/import');
    $this->submitForm(['files[archive]' => $zip_path], 'Import Content');

    return $uuid;
  }

  /**
   * Tests that an unknown field is reported before anything is imported.
   *
   * There is no working "Import Content" action to offer once a field
   * issue is found — proceeding is guaranteed to fail on that same field
   * — so this also asserts the button is absent and, since
   * default_content_ui_mapping isn't installed in this test, that a link
   * to enable it is offered instead.
   */
  public function testUnknownFieldIsReportedBeforeImporting() {
    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    $uuid = $this->buildArchiveWithUnknownField();

    $this->assertSession()->pageTextContains("Field 'field_does_not_exist'");
    $this->assertSession()->buttonNotExists('Import Content');
    $this->assertSession()->linkExists('Enable the Default Content UI Mapping module to fix field names during import');
    $this->assertNull(\Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid));
  }

  /**
   * Tests that a field issue links to Field Mapping when it's installed.
   */
  public function testUnknownFieldLinksToFieldMappingWhenInstalled() {
    \Drupal::service('module_installer')->install(['default_content_ui_mapping']);

    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    $this->buildArchiveWithUnknownField();

    $this->assertSession()->linkExists('Go to Field Mapping to add a mapping rule');
    $this->assertSession()->linkNotExists('Enable the Default Content UI Mapping module to fix field names during import');
  }

  /**
   * Tests that cancelling the report discards the import entirely.
   */
  public function testCancellingReportDiscardsImport() {
    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    $uuid = $this->buildArchiveWithUnknownField();
    $this->submitForm([], 'Cancel');

    $this->assertSession()->pageTextContains('Import cancelled.');
    $this->assertNull(
      \Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid),
      'Cancelling the report must not import anything.'
    );

    // Re-visiting the report directly afterward finds nothing pending.
    $this->drupalGet('/admin/config/development/default-content/import/confirm');
    $this->assertSession()->pageTextContains('There is no pending import to review.');
  }

  /**
   * Tests that the "Skip the dry-run report" setting restores one-step import.
   */
  public function testSkipDryRunSettingImportsInOneStep() {
    \Drupal::configFactory()->getEditable('default_content_ui.settings')
      ->set('skip_import_dry_run', TRUE)
      ->save();

    $admin_user = $this->drupalCreateUser(['default content import', 'access administration pages']);
    $this->drupalLogin($admin_user);

    [$uuid, $zip_path] = $this->buildArchiveWithNode('One Step Node');

    $this->drupalGet('/admin/config/development/default-content/import');
    $this->submitForm(['files[archive]' => $zip_path], 'Import Content');

    $this->assertSession()->pageTextContains('Content import completed successfully.');
    $this->assertNotNull(\Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid));
  }

}
