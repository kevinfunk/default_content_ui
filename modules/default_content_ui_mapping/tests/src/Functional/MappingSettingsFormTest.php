<?php

namespace Drupal\Tests\default_content_ui_mapping\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Default Content UI Mapping settings form.
 *
 * MappingSettingsForm had no test coverage at all — only the underlying
 * MappingSubscriber logic was tested against hand-built config arrays,
 * never the admin form's own build/validate/submit cycle that produces
 * that config in the first place.
 *
 * @group default_content_ui
 */
class MappingSettingsFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui_mapping', 'default_content_ui', 'system', 'user'];

  /**
   * Tests that a valid submission persists every section to config.
   */
  public function testValidSubmissionPersistsAllSections() {
    $admin_user = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin_user);

    $this->drupalGet('/admin/config/development/default-content/mapping');
    $this->assertSession()->statusCodeEquals(200);

    $edit = [
      'translation_handling[strip_translations]' => TRUE,
      'translation_handling[fallback_langcode]' => 'en',
      'exclusion_handling[exclusions_wrapper][excluded_fields][0][field_name]' => 'field_old_data',
      'value_exclusion_handling[value_exclusions_wrapper][value_exclusions][0][field_name]' => 'roles',
      'value_exclusion_handling[value_exclusions_wrapper][value_exclusions][0][property]' => 'target_id',
      'value_exclusion_handling[value_exclusions_wrapper][value_exclusions][0][value]' => 'member',
      'field_mapping[mappings_wrapper][mappings][0][source]' => 'incoming_field',
      'field_mapping[mappings_wrapper][mappings][0][target]' => 'field_media_image',
    ];
    $this->submitForm($edit, 'Save configuration');

    $this->assertSession()->pageTextContains('The configuration options have been saved.');

    $config = $this->config('default_content_ui_mapping.settings');
    $this->assertTrue($config->get('strip_translations'));
    $this->assertSame('en', $config->get('fallback_langcode'));
    $this->assertSame([
      ['entity_type' => '', 'bundle' => '', 'field_name' => 'field_old_data'],
    ], $config->get('excluded_fields'));
    $this->assertSame([
      [
        'entity_type' => '',
        'bundle' => '',
        'field_name' => 'roles',
        'property' => 'target_id',
        'value' => 'member',
      ],
    ], $config->get('value_exclusions'));
    $this->assertSame([
      ['source' => 'incoming_field', 'target' => 'field_media_image'],
    ], $config->get('mappings'));
  }

  /**
   * Tests that an invalid machine name is rejected and nothing is saved.
   */
  public function testInvalidMappingSourceIsRejected() {
    $admin_user = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin_user);

    $this->drupalGet('/admin/config/development/default-content/mapping');

    $edit = [
      'field_mapping[mappings_wrapper][mappings][0][source]' => 'Bad Source!',
      'field_mapping[mappings_wrapper][mappings][0][target]' => 'field_media_image',
    ];
    $this->submitForm($edit, 'Save configuration');

    $this->assertSession()->pageTextContains('Source machine names must contain only lowercase letters, numbers, and underscores.');
    $this->assertSession()->pageTextNotContains('The configuration options have been saved.');

    $this->assertSame([], $this->config('default_content_ui_mapping.settings')->get('mappings') ?? []);
  }

  /**
   * Tests that the unsaved-changes warning library is attached to the form.
   *
   * The library itself relies on native browser behavior (beforeunload)
   * that isn't practical to exercise here — this only guards against the
   * library or its marker class silently falling off the form in a
   * future refactor.
   */
  public function testUnsavedChangesLibraryIsAttached() {
    $admin_user = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($admin_user);

    $this->drupalGet('/admin/config/development/default-content/mapping');

    $this->assertSession()->elementExists('css', 'form.dcu-mapping-settings-form');
    $this->assertSession()->responseContains('default_content_ui_mapping/js/unsaved-changes.js');
  }

}
