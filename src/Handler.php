<?php

declare(strict_types = 1);

namespace Grasmash\ComposerScaffold;

use Composer\Package\PackageInterface;
use Composer\Script\Event;
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
   * The Composer service.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * Composer's I/O service.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  /**
   * An array of allowed packages keyed by package name.
   *
   * @var \Composer\Package\Package[]
   */
  protected $allowedPackages;

  /**
   * Handler constructor.
   *
   * @param \Composer\Composer $composer
   *   The Composer service.
   * @param \Composer\IO\IOInterface $io
   *   The Composer I/O service.
   */
  public function __construct(Composer $composer, IOInterface $io) {
    $this->composer = $composer;
    $this->io = $io;
  }

  /**
   * Post install command event to execute the scaffolding.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public function onPostCmdEvent(Event $event) {
    $this->scaffold();
    // Generate an autoload file in the document root that includes
    // the autoload.php file in the vendor directory, wherever that is.
    // Drupal requires this in order to easily locate relocated vendor dirs.
    $this->generateAutoload();
  }

  /**
   * Gets the array of file mappings provided by a given package.
   *
   * @param \Composer\Package\PackageInterface $package
   *   The Composer package from which to get the file mappings.
   *
   * @return array
   *   An associative array of file mappings, keyed by relative source file
   *   path. For example:
   *   [
   *     'path/to/source/file' => 'path/to/destination',
   *     'path/to/source/file' => false,
   *   ]
   */
  public function getPackageFileMappings(PackageInterface $package) : array {
    $package_extra = $package->getExtra();

    if (isset($package_extra['composer-scaffold']['file-mapping'])) {
      return $package_extra['composer-scaffold']['file-mapping'];
    }
    else {
      $this->io->writeError("The allowed package {$package->getName()} does not provide a file mapping for Composer Scaffold.");
      return [];
    }
  }

  /**
   * Copies all scaffold files from source to destination.
   */
  public function scaffold() {
    // Call any pre-scaffold scripts that may be defined.
    $dispatcher = new EventDispatcher($this->composer, $this->io);
    $dispatcher->dispatch(self::PRE_COMPOSER_SCAFFOLD_CMD);

    $this->allowedPackages = $this->getAllowedPackages();
    $file_mappings = $this->getFileMappingsFromPackages($this->allowedPackages);
    $file_mappings = $this->replaceLocationTokens($file_mappings);
    $this->moveScaffoldFiles($file_mappings);

    // Call post-scaffold scripts.
    $dispatcher->dispatch(self::POST_COMPOSER_SCAFFOLD_CMD);
  }

  /**
   * Generate the autoload file at the project root.
   *
   * Include the autoload file that Composer generated.
   */
  public function generateAutoload() {
    $vendorPath = $this->getVendorPath();
    $webroot = $this->getWebRoot();

    // Calculate the relative path from the webroot (location of the project
    // autoload.php) to the vendor directory.
    $fs = new SymfonyFilesystem();
    $relativeVendorPath = $fs->makePathRelative($vendorPath, realpath($webroot));

    $fs->dumpFile($webroot . "/autoload.php", $this->autoLoadContents($relativeVendorPath));
  }

  /**
   * Build the contents of the autoload file.
   *
   * @return string
   *   Return the contents for the autoload.php.
   */
  protected function autoLoadContents(string $relativeVendorPath) : string {
    $relativeVendorPath = rtrim($relativeVendorPath, '/');

    return <<<EOF
<?php

/**
 * @file
 * Includes the autoloader created by Composer.
 *
 * This file was generated by composer-scaffold.
 *.
 * @see composer.json
 * @see index.php
 * @see core/install.php
 * @see core/rebuild.php
 * @see core/modules/statistics/statistics.php
 */

return require __DIR__ . '/$relativeVendorPath/autoload.php';

EOF;
  }

  /**
   * Get the path to the 'vendor' directory.
   *
   * @return string
   *   The file path of the vendor directory.
   */
  public function getVendorPath() {
    $vendorDir = $this->composer->getConfig()->get('vendor-dir');
    $filesystem = new Filesystem();
    $filesystem->ensureDirectoryExists($vendorDir);
    return $filesystem->normalizePath(realpath($vendorDir));
  }

  /**
   * Retrieve the path to the web root.
   *
   * @return string
   *   The file path of the web root.
   *
   * @throws \Exception
   */
  public function getWebRoot() {
    $options = $this->getOptions();
    // @todo Allow packages to set web root location?
    if (empty($options['locations']['web-root'])) {
      throw new \Exception("The extra.composer-scaffold.location.web-root is not set in composer.json.");
    }
    return $options['locations']['web-root'];
  }

  /**
   * Retrieve a package from the current composer process.
   *
   * @param string $name
   *   Name of the package to get from the current composer installation.
   *
   * @return \Composer\Package\PackageInterface|null
   *   The Composer package.
   */
  protected function getPackage(string $name) {
    $package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');
    if (is_null($package)) {
      $this->io->write("<comment>Composer Scaffold could not find installed package `$name`.</comment>");
    }

    return $package;
  }

  /**
   * Retrieve options from optional "extra" configuration.
   *
   * @return array
   *   The composer-scaffold configuration array.
   */
  protected function getOptions() : array {
    $extra = $this->composer->getPackage()->getExtra() + ['composer-scaffold' => []];

    return $extra['composer-scaffold'] + [
      "allowed-packages" => [],
      "locations" => [],
      "symlink" => FALSE,
      "file-mapping" => [],
    ];
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
  public static function arrayMergeRecursiveDistinct(array &$array1, array &$array2) : array {
    $merged = $array1;
    foreach ($array2 as $key => &$value) {
      if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
        $merged[$key] = self::arrayMergeRecursiveDistinct($merged[$key], $value);
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
  protected function replaceLocationTokens(array $file_mappings) : array {
    $fs = new Filesystem();
    $options = $this->getOptions();
    $locations = $options['locations'];
    $locations = array_map(
      function ($location) use ($fs) {
        $fs->ensureDirectoryExists($location);
        $location = realpath($location);
        return $location;
      },
      $locations
    );

    $interpolator = new Interpolator();
    foreach ($file_mappings as $package_name => $files) {
      foreach ($files as $source => $destination) {
        if (is_string($destination)) {
          $file_mappings[$package_name][$source] = $interpolator->interpolate($locations, $destination);
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
  protected function moveScaffoldFiles(array $file_mappings) {
    $options = $this->getOptions();
    $symlink = $options['symlink'];

    foreach ($file_mappings as $package_name => $package_file_mappings) {
      if (!$this->getAllowedPackage($package_name)) {
        // @todo Add test case for this!
        // @todo Any package mentioned in the top-level composer.json's file
        // mappings should be implicitly allowed, which means we can remove this
        // warning.
        $this->io->writeError("The package <comment>$package_name</comment> is listed in file-mappings, but not an allowed package. Skipping.");
        continue;
      }
      $this->scaffoldPackageFiles($package_name, $package_file_mappings, $symlink);
    }
  }

  /**
   * Gets an allowed package from $this->allowedPackages array.
   *
   * @param string $package_name
   *   The Composer package name. E.g., drupal/core.
   *
   * @return \Composer\Package\Package|null
   *   The allowed Composer package, if it exists.
   */
  protected function getAllowedPackage($package_name) {
    if (array_key_exists($package_name, $this->allowedPackages)) {
      return $this->allowedPackages[$package_name];
    }

    return NULL;
  }

  /**
   * Gets a consolidated list of file mappings from all allowed packages.
   *
   * @param \Composer\Package\Package[] $allowed_packages
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
  protected function getFileMappingsFromPackages(array $allowed_packages) : array {
    $file_mappings = [];
    foreach ($allowed_packages as $name => $package) {
      $package_file_mappings = $this->getPackageFileMappings($package);
      $file_mappings = self::arrayMergeRecursiveDistinct($file_mappings, $package_file_mappings);
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
   * @return \Composer\Package\PackageInterface[]
   *   An array of allowed Composer packages.
   */
  protected function getAllowedPackages(): array {
    $options = $this->getOptions();
    $allowed_packages_list = $options['allowed-packages'];

    $allowed_packages = [];
    foreach ($allowed_packages_list as $name) {
      $package = $this->getPackage($name);
      if ($package instanceof PackageInterface) {
        $allowed_packages[$name] = $package;
      }
    }

    // Add root package at the end so that it overrides all the preceding
    // package.
    $root_package = $this->composer->getPackage();
    $allowed_packages[$root_package->getName()] = $root_package;

    return $allowed_packages;
  }

  /**
   * Gets the file path of a package.
   *
   * @param string $package_name
   *   The package name.
   *
   * @return string
   *   The file path.
   */
  protected function getPackagePath(string $package_name) : string {
    if ($package_name == $this->composer->getPackage()->getName()) {
      // This will respect the --working-dir option if Composer is invoked with
      // it. There is no API or method to determine the filesystem path of
      // a package's composer.json file.
      return getcwd();
    }
    else {
      return $this->composer->getInstallationManager()->getInstallPath($this->getPackage($package_name));
    }
  }

  /**
   * Moves a single scaffold file from source to destination.
   *
   * @param string $destination
   *   The file destination relative path.
   * @param string $source
   *   The file source relative path.
   * @param bool $symlink
   *   Whether the destination should be a symlink.
   * @param string $source_path
   *   The absolute path for the source file.
   *
   * @throws \Exception
   */
  protected function moveFile(string $destination, string $source, bool $symlink, string $source_path) {
    $fs = new Filesystem();
    $destination_path = str_replace('[web-root]', $this->getWebRoot(), $destination);

    // Get rid of the destination if it exists, and make sure that
    // the directory where it's going to be placed exists.
    @unlink($destination_path);
    $fs->ensureDirectoryExists(dirname($destination_path));
    $success = FALSE;
    if ($symlink) {
      try {
        $success = $fs->relativeSymlink($source_path, $destination_path);
      }
      catch (\Exception $e) {
      }
    }
    else {
      $success = copy($source_path, $destination_path);
    }
    $verb = $symlink ? 'symlink' : 'copy';
    if (!$success) {
      throw new \Exception("Could not $verb source file $source_path to $destination!");
    }
    else {
      $this->io->write("  - $verb source file <info>$source</info> to $destination");
    }
  }

  /**
   * Scaffolds the files for a specific package.
   *
   * @param string $package_name
   *   The name of the package. E.g., my/project.
   * @param array $package_file_mappings
   *   An associative array of source to destination file mappings.
   * @param bool $symlink
   *   Whether the destination should be a symlink.
   */
  protected function scaffoldPackageFiles(string $package_name, array $package_file_mappings, bool $symlink) {
    $this->io->write("Scaffolding files for <comment>$package_name</comment> package");
    foreach ($package_file_mappings as $source => $destination) {
      if ($destination && is_string($destination)) {
        $package_path = $this->getPackagePath($package_name);
        $source_path = $package_path . '/' . $source;
        if (!file_exists($source_path)) {
          $this->io->writeError("Could not find source file <comment>$source_path</comment> for package <comment>$package_name</comment>\n");
          continue;
        }
        if (is_dir($source_path)) {
          $this->io->writeError("<comment>$source_path</comment> in <comment>$package_name</comment> is a directory; only files may be scaffolded.");
          continue;
        }
        $this->moveFile($destination, $source, $symlink, $source_path);
      }
    }
  }

}
