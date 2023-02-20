<?php

declare(strict_types=1);

namespace B13\Typo3Updater;

use B13\Typo3Updater\Command\Typo3CommandProvider;
use Composer\Composer;
use Composer\Console\Application;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable as CapableInterface;
use Composer\Plugin\PluginInterface;

final class Plugin implements PluginInterface, CapableInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'ochorocho/tdk-composer-plugin';

    protected Application $application;
    protected Composer $composer;
    protected IOInterface $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->application = new Application();
        $this->application->setAutoExit(false);
    }

    public static function getSubscribedEvents()
    {
        return [
//            ScriptEvents::POST_ROOT_PACKAGE_INSTALL => [
//                ['cloneRepository', 0]
//            ],
//            ScriptEvents::POST_CREATE_PROJECT_CMD => [
//                ['gitConfig', 0],
//                ['createHooks', 0],
//                ['ddevConfig', 0],
//                ['commitTemplate', 0],
//                ['showInformation', 0]
//            ]
        ];
    }

//    public function getCapabilities(): array
//    {
////        return [
////            CommandProviderCapability::class => CommandProvider::class
////        ];
//        return [];
//    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement deactivate() method.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // TODO: Implement uninstall() method.
    }

    public function getCapabilities()
    {
        return [
            CommandProvider::class => Typo3CommandProvider::class
        ];
    }
}
