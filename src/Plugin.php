<?php

declare(strict_types=1);

namespace RulesHub\Cli;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

/**
 * Composer plugin entry point. Hooks PackageEvents so the binary download
 * fires whenever ruleshub/cli is installed or updated as a dependency in
 * the consumer's project — Composer's `post-install-cmd` would only fire
 * for the root package, so a plugin is the right level to subscribe at.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;

        // Bootstrap install: when this plugin is just being added via
        // `composer require ruleshub/cli`, Composer fires the
        // POST_PACKAGE_INSTALL event for our own package *before* it
        // loads our plugin code, so onPackageEvent never sees that
        // first install. Detect by checking whether the native binary
        // is on disk yet, and trigger Installer here if not.
        $binDir = dirname(__DIR__) . '/bin';
        $binName = 'ruleshub-bin' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_file($binDir . '/' . $binName)) {
            Installer::install($io);
        }
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No persistent state to clean.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Composer removes the package directory itself; binary goes with it.
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageEvent',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageEvent',
        ];
    }

    public function onPackageEvent(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $package = match (true) {
            $operation instanceof InstallOperation => $operation->getPackage(),
            $operation instanceof UpdateOperation  => $operation->getTargetPackage(),
            default => null,
        };

        if ($package === null || $package->getName() !== 'ruleshub/cli') {
            return;
        }

        Installer::install($this->io);
    }
}
