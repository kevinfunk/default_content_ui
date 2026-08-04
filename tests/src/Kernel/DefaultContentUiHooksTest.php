<?php

namespace Drupal\Tests\default_content_ui\Kernel;

use Drupal\Core\Plugin\CachedDiscoveryClearerInterface;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests DefaultContentUiHooks' hook_modules_installed() implementation.
 *
 * @group default_content_ui
 */
class DefaultContentUiHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['default_content_ui', 'system', 'user', 'file'];

  /**
   * Tests that installing a module (not syncing) clears the plugin caches.
   *
   * The "Export Default Content" bulk action derives one instance per
   * content entity type, but neither the entity type list nor the action
   * plugin cache built from it are tagged to invalidate when a module
   * adds a new entity type — so without this, the bulk action for it
   * would stay missing until an unrelated, manual cache rebuild. Verified
   * via a mock rather than end-to-end: KernelTestBase's cache backend
   * doesn't reproduce the cross-request persisted-cache staleness this
   * guards against, only real separate page requests do.
   */
  public function testModulesInstalledClearsPluginCachesWhenNotSyncing() {
    $clearer = $this->createMock(CachedDiscoveryClearerInterface::class);
    $clearer->expects($this->once())->method('clearCachedDefinitions');
    $this->container->set('plugin.cache_clearer', $clearer);

    \Drupal::service('default_content_ui.hooks')->modulesInstalled(['entity_test'], FALSE);
  }

  /**
   * Tests that a config sync import does not trigger the cache refresh.
   *
   * $is_syncing means this fired as a side effect of importing
   * configuration, not an admin actively installing a module — clearing
   * plugin caches mid-sync is unnecessary work the sync process's own
   * final cache rebuild already covers.
   */
  public function testModulesInstalledSkipsClearWhenSyncing() {
    $clearer = $this->createMock(CachedDiscoveryClearerInterface::class);
    $clearer->expects($this->never())->method('clearCachedDefinitions');
    $this->container->set('plugin.cache_clearer', $clearer);

    \Drupal::service('default_content_ui.hooks')->modulesInstalled(['entity_test'], TRUE);
  }

}
