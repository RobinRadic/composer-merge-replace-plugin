<?php

namespace Radic\ComposerMergeReplacePlugin;

use Composer\Composer;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Semver\Constraint\MatchAllConstraint;
use UnexpectedValueException;
use Wikimedia\Composer\Merge\V2\MergePlugin;
use Wikimedia\Composer\Merge\V2\MissingFileException;

class MergeReplacePlugin implements PluginInterface, EventSubscriberInterface
{

    protected $composer;

    protected $io;

    protected $logger;

    protected $vendorDir;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->logger   = new Logger('merge-replace', $io);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    public function excludePackageClassmaps()
    {
        $this->vendorDir = $this->composer->getConfig()->get('vendor-dir');
        if ($plugin = $this->getWikimediaPlugin()) {
            $ref      = new \ReflectionClass($plugin);
            $stateRef = $ref->getProperty('state');
            $stateRef->setAccessible(true);
            /** @var \Wikimedia\Composer\Merge\V2\PluginState $state */
            $state = $stateRef->getValue($plugin);
            $state->loadSettings();
            $globs    = array_merge($state->getIncludes(), $state->getRequires());
            $replaces = [];
            foreach ($this->getFilePathsFromGlobs($globs) as $paths) {
                foreach ($paths as $path) {
                    $json                        = $this->readPackageJson($path);
                    $replaces[ $json[ 'name' ] ] = $this->createLink($json[ 'name' ]);
                }
            }
            $this->mergeRootComposerReplaces($replaces);
        }
    }

    protected function createLink($packageName)
    {
        return new Link($this->composer->getPackage()->getName(), $packageName, new MatchAllConstraint(), Link::TYPE_REPLACE, '*');
    }

    protected function mergeRootComposerReplaces(array $replaces)
    {
        $result = array_replace_recursive($this->composer->getPackage()->getReplaces(), $replaces);
        $this->composer->getPackage()->setReplaces($result);
    }

    protected function isValidPath($path)
    {
        return is_dir($path) || is_file($path);
    }

    /**
     * @return \Wikimedia\Composer\Merge\V2\MergePlugin|null
     */
    protected function getWikimediaPlugin()
    {
        foreach ($this->composer->getPluginManager()->getPlugins() as $plugin) {
            if(get_class($plugin) === 'Wikimedia\\Composer\\Merge\\V2\\MergePlugin'){
                return $plugin;
            }
        }
        return null;
    }

    protected function getFilePathsFromGlobs($patterns, $required = false)
    {
        return array_map(
            function ($files, $pattern) use ($required) {
                if ($required && ! $files) {
                    throw new \RuntimeException(
                        "merge-replace: No files matched required '{$pattern}'"
                    );
                }
                return $files;
            },
            array_map('glob', $patterns),
            $patterns
        );
    }

    /**
     * Read the contents of a composer.json style file into an array.
     *
     * The package contents are fixed up to be usable to create a Package
     * object by providing dummy "name" and "version" values if they have not
     * been provided in the file. This is consistent with the default root
     * package loading behavior of Composer.
     *
     * @param string $path
     * @return array
     */
    protected function readPackageJson($path)
    {
        $file = new JsonFile($path);
        $json = $file->read();
        if ( ! isset($json[ 'name' ])) {
            $json[ 'name' ] = 'merge-plugin/' .
                strtr($path, DIRECTORY_SEPARATOR, '-');
        }
        if ( ! isset($json[ 'version' ])) {
            $json[ 'version' ] = '1.0.0';
        }
        return $json;
    }

    /**
     * @param array $json
     * @return CompletePackage
     */
    protected function loadPackage(array $json)
    {
        $loader  = new ArrayLoader();
        $package = $loader->load($json);
        // @codeCoverageIgnoreStart
        if ( ! $package instanceof CompletePackage) {
            throw new UnexpectedValueException(
                'Expected instance of CompletePackage, got ' .
                get_class($package)
            );
        }
        // @codeCoverageIgnoreEnd
        return $package;
    }

    public function onCommand(CommandEvent $event)
    {

        $this->io->write('exclude-class-map onCommand');
    }

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_AUTOLOAD_DUMP => [
                [ 'preAutoloadDump', 0 ],
            ],
        ];
    }

    public function preAutoloadDump(Event $event)
    {
        $this->excludePackageClassmaps();
    }

}
