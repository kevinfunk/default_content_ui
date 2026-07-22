<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\default_content_ui\Form\SettingsForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests server-side validation of submitted entity types for the settings form.
 *
 * @group default_content_ui
 */
class DefaultContentUiSettingsFormTest extends KernelTestBase {

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
   * Tests that a fresh install leaves local_export_types unset.
   *
   * SettingsForm::buildForm() and the code that actually gates the
   * "Export" tab/operation link (ExportEntityLocalTask,
   * DefaultContentUiHooks::entityOperation()) both treat NULL as "every
   * eligible entity type is enabled by default" — but only if this key is
   * genuinely unset. Shipping it as an empty array in
   * config/install/default_content_ui.settings.yml would silently enable
   * nothing until an admin visits Settings.
   */
  public function testLocalExportTypesUnsetOnFreshInstall() {
    $this->assertNull(\Drupal::config('default_content_ui.settings')->get('local_export_types'));
  }

  /**
   * Tests that a forged, ineligible entity type ID is dropped.
   *
   * Drupal's tableselect element returns whatever keys the client
   * submitted, not validated against #options, so a forged POST could
   * otherwise smuggle in an entity type that isn't actually offered here
   * (e.g. one without a canonical link template, which the "Export" tab
   * and operation link both need).
   */
  public function testSubmitDropsIneligibleEntityTypes() {
    $form = SettingsForm::create(\Drupal::getContainer());

    $form_state = new FormState();
    $form_state->setValues([
      'local_export_types' => [
        'node' => 'node',
        // 'user_role' is a real, registered entity type, but it's in the
        // "config" group, not "content".
        'user_role' => 'user_role',
      ],
      'references' => FALSE,
    ]);

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $saved = \Drupal::config('default_content_ui.settings')->get('local_export_types');
    $this->assertContains('node', $saved);
    $this->assertNotContains('user_role', $saved);
  }

  /**
   * Tests that a submission of only ineligible entity type IDs saves none.
   */
  public function testSubmitWithOnlyIneligibleEntityTypesSavesNone() {
    $form = SettingsForm::create(\Drupal::getContainer());

    $form_state = new FormState();
    $form_state->setValues([
      'local_export_types' => [
        'user_role' => 'user_role',
      ],
      'references' => FALSE,
    ]);

    $form_array = [];
    $form->submitForm($form_array, $form_state);

    $saved = \Drupal::config('default_content_ui.settings')->get('local_export_types');
    $this->assertSame([], $saved);
  }

}
