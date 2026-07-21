<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\default_content_ui\Form\ExportBulkForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests server-side validation of submitted entity types for bulk export.
 *
 * @group default_content_ui
 */
class DefaultContentUiExportFormTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'system', 'user', 'file', 'node', 'field', 'text'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['default_content_ui']);
  }

  /**
   * Tests that a forged, non-content entity type ID is dropped.
   *
   * Drupal's tableselect element returns whatever keys the client
   * submitted, not validated against #options, so a forged POST could
   * otherwise smuggle a non-content (e.g. config) entity type ID into
   * ExportBatch::export(), which the core DefaultContent Exporter isn't
   * built to handle.
   */
  public function testSubmitDropsNonContentEntityTypes() {
    $form = ExportBulkForm::create(\Drupal::getContainer());

    $form_state = new FormState();
    $form_state->setValues([
      'bulk_export_types' => [
        'node' => 'node',
        // 'user_role' is a real, registered entity type, but it's in the
        // "config" group, not "content".
        'user_role' => 'user_role',
      ],
      'references' => FALSE,
    ]);

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $saved = \Drupal::config('default_content_ui.settings')->get('bulk_export_types');
    $this->assertContains('node', $saved);
    $this->assertNotContains('user_role', $saved);
  }

  /**
   * Tests that a submission of only forged entity type IDs exports nothing.
   */
  public function testSubmitWithOnlyNonContentEntityTypesExportsNothing() {
    $form = ExportBulkForm::create(\Drupal::getContainer());

    $form_state = new FormState();
    $form_state->setValues([
      'bulk_export_types' => [
        'user_role' => 'user_role',
      ],
      'references' => FALSE,
    ]);

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $saved = \Drupal::config('default_content_ui.settings')->get('bulk_export_types');
    $this->assertSame([], $saved);
  }

}
