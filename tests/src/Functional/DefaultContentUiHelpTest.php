<?php

namespace Drupal\Tests\default_content_ui\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the module's help page.
 *
 * @group default_content_ui
 */
class DefaultContentUiHelpTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'help'];

  /**
   * Tests that the module's help page renders.
   */
  public function testHelpPageRenders() {
    $admin_user = $this->drupalCreateUser(['access help pages']);
    $this->drupalLogin($admin_user);

    $this->drupalGet('/admin/help/default_content_ui');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Default Content system');
  }

}
