<?php

namespace Grasmash\ComposerScaffold;

use Composer\Package\Package;
use Composer\Script\Event;
use Composer\Plugin\CommandEvent;
use Composer\Composer;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Core class of the plugin, contains all logic which files should be fetched.
 */
class Handler {

  const PRE_COMPOSER_SCAFFOLD_CMD = 'pre-composer-scaffold-cmd';
  const POST_COMPOSER_SCAFFOLD_CMD = 'post-composer-scaffold-cmd';

  /**
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * @var bool
   *
   * A boolean indicating if progress should be displayed.
   */
  protected $progress;

  /**
   * @var Package[]
   *
   * An array of allowed packages keyed by package name.
   */
  protected $allowedPackages;

  /**
   * Handler constructor.
   *
   * @param \Composer\Composer $composer
   * @param \Composer\IO\IOInterface $io
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
    $this->progress = TRUE;
  }

  /**
   * Get the command options.
   *
   * @param \Composer\Plugin\CommandEvent $event
   */
  public function onCmdBeginsEvent(CommandEvent $event) {
    if ($event->getInput()->hasOption('no-progress')) {
      $this->progress = !($event->getInput()->getOption('no-progress'));
    }
    else {
      $this->progress = TRUE;
    }
  }

  /**
   * Post install command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   */
  public function onPostCmdEvent(Event $event) {
    if (isset($this->drupalCorePackage)) {
      $this->copyAllFiles();
      // Generate the autoload.php file after generating the scaffold files.
      $this->generateAutoload();
    }
  }

  /**
   * Gets the array of file mappings provided by a given package.
   *
   * @param \Composer\Package\Package $package
   *
   * @return array
   *   An associative array of file mappings, keyed by relative source file
   *   path. For example:
   *   [
   *     'path/to/source/file' => 'path/to/destination',
   *     'path/to/source/file' => false,
   *   ]
   */
  public function getPackageFileMappings(Package $package) {
    $package_extra = $package->getExtra();
    if (!array_key_exists('composer-scaffold', $package_extra) || !array_key_exists('file-mapping', $package_extra['composer-scaffold'])) {
      $this->io->writeError("The allowed package {$package->getName()} does not provide a file mapping for Composer Scaffold.");
      $package_file_mappings = [];
    }
    else {
      $package_file_mappings = $package_extra['composer-scaffold']['file-mapping'];
    }

    return $package_file_mappings;
  }

  /**
   * Copies all scaffold files from source to destination.
   */
  public function copyAllFiles() {
    // Call any pre-scaffold scripts that may be defined.
    $dispatcher = new EventDispatcher($this->composer, $this->io);
    $dispatcher->dispatch(self::PRE_COMPOSER_SCAFFOLD_CMD);

    $this->allowedPackages = $this->getAllowedPackages();
    $file_mappings = $this->getFileMappingsFromPackages($this->allowedPackages);
    $file_mappings = $this->replaceWebRootToken($file_mappings);
    $this->copyFiles($file_mappings);

    // Call post-scaffold scripts.
    $dispatcher->dispatch(self::POST_COMPOSER_SCAFFOLD_CMD);
  }

  /**
   * Generate the autoload file at the project root.  Include the
   * autoload file that Composer generated.
   */
  public function generateAutoload() {
    $vendorPath = $this->getVendorPath();
    $webroot = $this->getWebRoot();

    // Calculate the relative path from the webroot (location of the
    // project autoload.php) to the vendor directory.
    $fs = new SymfonyFilesystem();
    $relativeVendorPath = $fs->makePathRelative($vendorPath, realpath($webroot));

    $fs->dumpFile($webroot . "/autoload.php", $this->autoLoadContents($relativeVendorPath));
  }

  /**
   * Build the contents of the autoload file.
   *
   * @return string
   */
  protected function autoLoadContents($relativeVendorPath) {
    $relativeVendorPath = rtrim($relativeVendorPath, '/');

    $autoloadContents = <<<EOF
<?php

/**
 * @file
 * Includes the autoloader created by Composer.
 *
 * This file was generated by drupal-composer/drupal-scaffold.
 * https://github.com/drupal-composer/drupal-scaffold
 *
 * @see composer.json
 * @see index.php
 * @see core/install.php
 * @see core/rebuild.php
 * @see core/modules/statistics/statistics.php
 */

return require __DIR__ . '/$relativeVendorPath/autoload.php';

EOF;
    return $autoloadContents;
  }

  /**
   * Get the path to the 'vendor' directory.
   *
   * @return string
   */
  public function getVendorPath() {
    $config = $this->composer->getConfig();
    $filesystem = new Filesystem();
    $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
    $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));

    return $vendorPath;
  }

  /**
   * Retrieve the path to the web root.
   *
   * @return string
   * @throws \Exception
   */
  public function getWebRoot() {
    $options = $this->getOptions();
    // @todo Allow packages to set web root location?
    if (!array_key_exists('web-root', $options['locations'])) {
      throw new \Exception("The extra.composer-scaffold.location.web-root is not set in composer.json.");
    }
    $webroot = $options['locations']['web-root'];

    return $webroot;
  }

  /**
   * Retrieve a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return \Composer\Package\PackageInterface
   */
  protected function getPackage($name) {
    $package =  $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
    if (is_null($package)) {
      $this->io->write("<comment>Composer Scaffold could not find installed package `$name`.</comment>");
    }

    return $package;
  }

  /**
   * Retrieve options from optional "extra" configuration.
   *
   * @return array
   */
  protected function getOptions() {
    $extra = $this->composer->getPackage()->getExtra() + ['composer-scaffold' => []];
    $options = $extra['composer-scaffold'] + [
      "allowed-packages" => [],
      "locations" => [],
      "symlink" => false,
      "file-mapping" => [],
    ];

    return $options;
  }

  /**
   * Merges arrays recursively while preserving.
   *
   * @param array $array1
   *   The first array.
   * @param array $array2
   *   The second array.
   *
   * @return array
   *   The merged array.
   *
   * @see http://php.net/manual/en/function.array-merge-recursive.php#92195
   */
  public static function arrayMergeRecursiveDistinct(
    array &$array1,
    array &$array2
  ) {
    $merged = $array1;
    foreach ($array2 as $key => &$value) {
      if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
        $merged[$key] = self::arrayMergeRecursiveDistinct($merged[$key],
          $value);
      }
      else {
        $merged[$key] = $value;
      }
    }
    return $merged;
  }

  /**
   * Replaces '[web-root]' token in file mappings.
   *
   * @param array $file_mappings
   *   An multidimensional array of file mappings, as returned by
   *   self::getFileMappingsFromPackages().
   *
   * @return array
   *   An multidimensional array of file mappings with tokens replaced.
   */
  protected function replaceWebRootToken($file_mappings) {
    $webroot = realpath($this->getWebRoot());
    foreach ($file_mappings as $package_name => $files) {
      foreach ($files as $source => $target) {
        if (is_string($target)) {
          $file_mappings[$package_name][$source] = str_replace('[web-root]', $webroot, $target);
        }
      }
    }
    return $file_mappings;
  }

  /**
   * Copy all files, as defined by $file_mappings.
   *
   * @param array $file_mappings
   *   An multidimensional array of file mappings, as returned by
   *   self::getFileMappingsFromPackages().
   */
  protected function copyFiles($file_mappings) {
    $options = $this->getOptions();
    $symlink = $options['symlink'];
    foreach ($file_mappings as $package_name => $files) {
      foreach ($files as $source => $target) {
        if ($target && $this->getAllowedPackage($package_name)) {
          // @todo Fix this! Drupal core actually isn't in vendor.
          $source_path = $this->getVendorPath() . '/' . $package_name . '/' . $source;
          if (!file_exists($source)) {
            $this->io->writeError("Could not find source file $source for package $package_name");
          }
          if ($symlink) {
            $success = symlink($target, $source_path);
          }
          else {
            $success = copy($source_path, $target);
          }
          if (!$success) {
            $verb = $symlink ? 'symlink' : 'copy';
            $this->io->writeError("Could not $verb source file $source to $target");
          }
        }
      }
    }
  }

  /**
   * Gets an allowed package from $this->allowedPackages array.
   *
   * @param string $package_name
   *   The Composer package name. E.g., drupal/core.
   *
   * @return \Composer\Package\Package|null
   */
  public function getAllowedPackage($package_name) {
    if (array_key_exists($package_name, $this->allowedPackages)) {
      return $this->allowedPackages[$package_name];
    }

    return null;
  }

  /**
   * Gets a consolidated list of file mappings from all allowed packages.
   *
   * @param Package[] $allowed_packages
   *   A multidimensional array of file mappings, as returned by
   *   self::getAllowedPackages().
   *
   * @return array
   *   An multidimensional array of file mappings, which looks like this:
   *   [
   *     'drupal/core' => [
   *       'path/to/source/file' => 'path/to/destination',
   *       'path/to/source/file' => false,
   *     ],
   *     'some/package' => [
   *       'path/to/source/file' => 'path/to/destination',
   *     ],
   *   ]
   */
  protected function getFileMappingsFromPackages($allowed_packages): array {
    $file_mappings = [];
    foreach ($allowed_packages as $name => $package) {
      $package_file_mappings = $this->getPackageFileMappings($package);
      $file_mappings = self::arrayMergeRecursiveDistinct($file_mappings,
        $package_file_mappings);
    }
    return $file_mappings;
  }

  /**
   * Gets a list of all packages that are allowed to copy scaffold files.
   *
   * Configuration for packages specified later will override configuration
   * specified by packages listed earlier. In other words, the last listed
   * package has the highest priority. The root package will always be returned
   * at the end of the list.
   *
   * @return array
   */
  protected function getAllowedPackages(): array {
    $options = $this->getOptions();
    $allowed_packages_list = $options['allowed-packages'];

    $allowed_packages = [];
    foreach ($allowed_packages_list as $name) {
      $package = $this->getPackage($name);
      if (!is_null($package)) {
        $allowed_packages[$name] = $package;
      }
    }

    // Add root package at end.
    $allowed_packages[$this->composer->getPackage()
      ->getName()] = $this->composer->getPackage();

    return $allowed_packages;
  }

}