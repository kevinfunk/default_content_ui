<?php

namespace Drupal\Tests\default_content_ui\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\system\Entity\Action;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Entity\View;

/**
 * Tests the Default Content UI export features.
 *
 * @group default_content_ui
 */
class DefaultContentUiExportTest extends BrowserTestBase {

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
    'node',
    'taxonomy',
    'user',
    'file',
    'system',
    'field',
    'views',
    'serialization',
    'default_content_ui',
    'block',
  ];

  /**
   * A test admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * A test node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * A test taxonomy term.
   *
   * @var \Drupal\taxonomy\TermInterface
   */
  protected $term;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Needed so the "Export" local task is actually rendered on the node's
    // canonical page for the CSRF-token-carrying clickLink() flow below —
    // the 'stark' theme places no blocks by default.
    $this->drupalPlaceBlock('local_tasks_block', ['region' => 'content']);

    $vocabulary = Vocabulary::create([
      'vid' => 'tags',
      'name' => 'Tags',
    ]);
    $vocabulary->save();

    $this->term = Term::create([
      'vid' => 'tags',
      'name' => 'Test Tag',
    ]);
    $this->term->save();

    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);

    $field_name = 'field_tags';
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'taxonomy_term',
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => 'page',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['tags' => 'tags'],
        ],
      ],
    ])->save();

    $this->node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Test Page with Dependency',
      'field_tags' => [$this->term->id()],
    ]);

    // Clear caches to ensure Action Plugins are discovered.
    $this->container->get('plugin.manager.action')->clearCachedDefinitions();

    // Ensure the Action exists and is Enabled.
    $action = Action::load('default_content_export_node');
    if (!$action) {
      $action = Action::create([
        'id' => 'default_content_export_node',
        'label' => 'Export Default Content',
        'type' => 'node',
        'plugin' => 'default_content_ui_export_action:node',
      ]);
    }
    $action->set('status', TRUE);
    $action->save();

    $this->adminUser = $this->drupalCreateUser([
      'default content export',
      'default content import',
      'administer site configuration',
      'access administration pages',
      'bypass node access',
      'access content',
    ]);
  }

  /**
   * Test Views Bulk Export Action.
   */
  public function testViewsBulkExport() {
    $view = View::create([
      'id' => 'test_export_view',
      'base_table' => 'node_field_data',
      'label' => 'Test Export View',
    ]);

    $view->addDisplay('default', 'Master', 'default');
    $default = &$view->getDisplay('default');

    $default['display_options'] = [
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
      'style' => ['type' => 'table'],
      'row' => ['type' => 'fields'],
      'fields' => [
        'node_bulk_form' => [
          'id' => 'node_bulk_form',
          'table' => 'node',
          'field' => 'node_bulk_form',
          'plugin_id' => 'bulk_form',
          'entity_type' => 'node',
          'include_exclude' => 'include',
          'selected_actions' => [
            'default_content_export_node' => 'default_content_export_node',
          ],
        ],
        'title' => [
          'id' => 'title',
          'table' => 'node_field_data',
          'field' => 'title',
          'plugin_id' => 'field',
          'entity_type' => 'node',
        ],
      ],
    ];

    $view->addDisplay('page', 'Page', 'page_1');
    $page = &$view->getDisplay('page_1');
    $page['display_options']['path'] = 'test-export-view';

    $view->save();
    \Drupal::service('router.builder')->rebuild();

    $node2 = $this->drupalCreateNode(['type' => 'page', 'title' => 'Page 2']);
    $node3 = $this->drupalCreateNode(['type' => 'page', 'title' => 'Page 3']);

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('test-export-view');
    $this->assertSession()->statusCodeEquals(200);

    // Dynamic Checkbox Selection.
    $checkboxes = $this->getSession()->getPage()->findAll('css', 'input[name^="node_bulk_form"]');
    $this->assertCount(3, $checkboxes, 'Found 3 checkboxes for the 3 nodes.');

    $edit = ['action' => 'default_content_export_node'];
    foreach ($checkboxes as $checkbox) {
      $edit[$checkbox->getAttribute('name')] = TRUE;
    }

    $this->submitForm($edit, 'Apply to selected items');

    $expected = [
      'content/node/' . $this->node->uuid() . '.yml',
      'content/node/' . $node2->uuid() . '.yml',
      'content/node/' . $node3->uuid() . '.yml',
    ];
    $this->verifyExportArchiveOnDisk($expected);
  }

  /**
   * Test Bulk Export WITH references.
   */
  public function testBulkExportWithReferences() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/development/default-content/export');

    $edit = [
      'bulk_export_types[node]' => 'node',
      'references' => TRUE,
    ];
    $this->submitForm($edit, 'Export Content');
    $this->assertSession()->statusCodeEquals(200);

    $expected = [
      'content/node/' . $this->node->uuid() . '.yml',
      'content/taxonomy_term/' . $this->term->uuid() . '.yml',
    ];
    $this->verifyExportArchiveOnDisk($expected);
  }

  /**
   * Test Bulk Export WITHOUT references.
   */
  public function testBulkExportNoReferences() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/development/default-content/export');

    $edit = [
      'bulk_export_types[node]' => 'node',
      'bulk_export_types[taxonomy_term]' => FALSE,
      'references' => FALSE,
    ];
    $this->submitForm($edit, 'Export Content');
    $this->assertSession()->statusCodeEquals(200);

    $expected = [
      'content/node/' . $this->node->uuid() . '.yml',
    ];
    $unexpected = [
      'content/taxonomy_term/' . $this->term->uuid() . '.yml',
    ];
    $this->verifyExportArchiveOnDisk($expected, $unexpected);
  }

  /**
   * Tests that the "Export" tab is visible by default on a fresh install.
   *
   * SettingsForm's tableselect defaults to "all eligible types enabled"
   * only when local_export_types is genuinely unset (NULL) — config
   * install YAML must not ship it as an empty array, which would leave
   * the "Export" tab hidden until an admin manually visits Settings.
   */
  public function testExportTabVisibleByDefaultOnFreshInstall() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet($this->node->toUrl());
    $this->assertSession()->linkExists('Export');
  }

  /**
   * Test Single Export WITH references.
   */
  public function testSingleExportWithReferences() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/development/default-content/settings');
    $this->submitForm(['local_export_types[node]' => 'node', 'references' => TRUE], 'Save configuration');
    $this->rebuildAll();

    // Navigate to the node's own page and follow the rendered local task
    // link, rather than building the URL directly: the CSRF token this
    // route now requires is only computed correctly against the current
    // browser session when generated as part of an actual page render.
    $this->drupalGet($this->node->toUrl());
    $this->clickLink('Export');
    // clickLink() clicks the element directly, bypassing drupalGet()'s
    // meta-refresh chasing — needed here since the export runs as a
    // multi-step, non-JS batch that progresses via meta refresh.
    $this->checkForMetaRefresh();
    $this->assertSession()->statusCodeEquals(200);

    $expected = [
      'content/node/' . $this->node->uuid() . '.yml',
      'content/taxonomy_term/' . $this->term->uuid() . '.yml',
    ];
    $this->verifyExportArchiveOnDisk($expected);
  }

  /**
   * Test Single Export WITHOUT references.
   */
  public function testSingleExportNoReferences() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/development/default-content/settings');
    $this->submitForm(['local_export_types[node]' => 'node', 'references' => FALSE], 'Save configuration');
    $this->rebuildAll();

    // See the comments in testSingleExportWithReferences() about why this
    // follows the rendered local task link instead of building the URL,
    // and why checkForMetaRefresh() is needed after clickLink().
    $this->drupalGet($this->node->toUrl());
    $this->clickLink('Export');
    $this->checkForMetaRefresh();
    $this->assertSession()->statusCodeEquals(200);

    $expected = [
      'content/node/' . $this->node->uuid() . '.yml',
    ];
    $unexpected = [
      'content/taxonomy_term/' . $this->term->uuid() . '.yml',
    ];

    $this->verifyExportArchiveOnDisk($expected, $unexpected);
  }

  /**
   * Tests that the single-entity export route requires a CSRF token.
   *
   * This route runs a batch and writes files on a plain GET — without a
   * token, a forced request (e.g. an <img> tag on an attacker-controlled
   * page) from an authenticated privileged user's browser could trigger an
   * export and clobber their pending download, purely from an unwanted
   * page visit.
   */
  public function testSingleExportRequiresCsrfToken() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet($this->node->toUrl('canonical')->toString() . '/default-content-export');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests that the legitimate auto-download flow still works.
   */
  public function testDownloadSucceedsInSameSession() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/development/default-content/export');
    $this->submitForm(['bulk_export_types[node]' => 'node', 'references' => FALSE], 'Export Content');

    // submitForm() auto-follows the <meta http-equiv="Refresh"> tag that
    // DefaultContentUiHooks::pageAttachments() adds, so the download has
    // already happened (and the session key already consumed) by the time
    // this assertion runs.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'attachment');

    // A second, manual request must not succeed again.
    $this->drupalGet('/admin/config/development/default-content/export/download');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests that another user cannot fetch someone else's export archive.
   *
   * The file_download hook also gates Drupal's public '/system/temporary'
   * route directly, bypassing ExportDownloadController's own session
   * check. Export filenames are time()-based, not random, so a permission
   * check alone would let any other user holding the same 'default
   * content export' permission download an archive they didn't generate
   * — one assembled under the *original* exporter's access checks,
   * potentially disclosing entities the second user can't themselves
   * view.
   */
  public function testDownloadIsScopedToOwningSession() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/config/development/default-content/export');
    $this->submitForm(['bulk_export_types[node]' => 'node', 'references' => FALSE], 'Export Content');
    $this->assertSession()->statusCodeEquals(200);

    $file_system = \Drupal::service('file_system');
    $temp_dir = $file_system->realpath('temporary://');
    $files = glob($temp_dir . '/default_content_export_*.zip');
    $this->assertNotEmpty($files, 'No export archive found on disk.');
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $filename = basename($files[0]);

    $other_user = $this->drupalCreateUser(['default content export', 'access administration pages']);
    $this->drupalLogin($other_user);

    $this->drupalGet('/system/temporary', ['query' => ['file' => $filename]]);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Checks the most recent ZIP file in temporary://.
   */
  protected function verifyExportArchiveOnDisk(array $expected_paths, array $unexpected_paths = []) {
    $file_system = \Drupal::service('file_system');
    // Ensure we look in the real system path for the temporary directory.
    $temp_dir = $file_system->realpath('temporary://');

    if (!$temp_dir) {
      $this->fail('Could not resolve realpath for temporary://');
    }

    $files = glob($temp_dir . '/*.zip');
    $this->assertNotEmpty($files, 'No ZIP export file found in ' . $temp_dir);

    // Sort by modification time descending (newest first).
    usort($files, function ($a, $b) {
      return filemtime($b) - filemtime($a);
    });

    $archive_path = $files[0];

    $zip = new \ZipArchive();
    $res = $zip->open($archive_path);
    $this->assertTrue($res === TRUE, 'Valid ZIP archive opened at ' . $archive_path);

    $found_files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $found_files[] = $zip->getNameIndex($i);
    }
    $zip->close();

    foreach ($expected_paths as $path) {
      $this->assertTrue(
        in_array($path, $found_files),
        "Archive should contain: $path. Found: " . implode(', ', $found_files)
      );
    }
    foreach ($unexpected_paths as $path) {
      $this->assertFalse(
        in_array($path, $found_files),
        "Archive should NOT contain: $path. Found: " . implode(', ', $found_files)
      );
    }
  }

}
