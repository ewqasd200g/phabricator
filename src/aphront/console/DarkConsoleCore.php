<?php

/**
 * @group console
 */
final class DarkConsoleCore {

  private $plugins = array();
  const STORAGE_VERSION = 1;

  public function __construct() {
    $symbols = id(new PhutilSymbolLoader())
      ->setType('class')
      ->setAncestorClass('DarkConsolePlugin')
      ->selectAndLoadSymbols();

    foreach ($symbols as $symbol) {
      $plugin = newv($symbol['name'], array());
      if (!$plugin->shouldStartup()) {
        continue;
      }
      $plugin->setConsoleCore($this);
      $plugin->didStartup();
      $this->plugins[$symbol['name']] = $plugin;
    }
  }

  public function getPlugins() {
    return $this->plugins;
  }

  public function getKey(AphrontRequest $request) {
    $plugins = $this->getPlugins();

    foreach ($plugins as $plugin) {
      $plugin->setRequest($request);
      $plugin->willShutdown();
    }

    foreach ($plugins as $plugin) {
      $plugin->didShutdown();
    }

    foreach ($plugins as $plugin) {
      $plugin->setData($plugin->generateData());
    }

    $plugins = msort($plugins, 'getOrderKey');

    $key = Filesystem::readRandomCharacters(24);

    $tabs = array();
    $data = array();
    foreach ($plugins as $plugin) {
      $class = get_class($plugin);
      $tabs[] = array(
        'class' => $class,
        'name'  => $plugin->getName(),
        'color' => $plugin->getColor(),
      );
      $data[$class] = $this->sanitizeForJSON($plugin->getData());
    }

    $storage = array(
      'vers' => self::STORAGE_VERSION,
      'tabs' => $tabs,
      'data' => $data,
      'user' => $request->getUser()
        ? $request->getUser()->getPHID()
        : null,
    );

    $cache = new PhabricatorKeyValueDatabaseCache();
    $cache = new PhutilKeyValueCacheProfiler($cache);
    $cache->setProfiler(PhutilServiceProfiler::getInstance());

    $cache->setKeys(
      array(
        'darkconsole:'.$key => json_encode($storage),
      ),
      $ttl = (60 * 60 * 6));

    return $key;
  }

  public function getColor() {
    foreach ($this->getPlugins() as $plugin) {
      if ($plugin->getColor()) {
        return $plugin->getColor();
      }
    }
  }

  public function render(AphrontRequest $request) {
    $user = $request->getUser();
    $visible = $user ? $user->getConsoleVisible() : true;

    return javelin_tag(
      'div',
      array(
        'id' => 'darkconsole',
        'class' => 'dark-console',
        'style' => $visible ? '' : 'display: none;',
        'data-console-key' => $this->getKey($request),
        'data-console-color' => $this->getColor(),
      ),
      '');
  }

  /**
   * Sometimes, tab data includes binary information (like INSERT queries which
   * write file data into the database). To successfully JSON encode it, we
   * need to convert it to UTF-8.
   */
  private function sanitizeForJSON($data) {
    if (is_array($data)) {
      foreach ($data as $key => $value) {
        $data[$key] = $this->sanitizeForJSON($value);
      }
      return $data;
    } else {
      return phutil_utf8ize($data);
    }
  }

}

