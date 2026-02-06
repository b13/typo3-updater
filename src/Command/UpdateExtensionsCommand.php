<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

/*
 * This file is part of the b13/typo3-updater Composer plugin by b13.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Composer\Command\BaseCommand;
use Composer\Console\Application;
use Composer\Console\Input\InputArgument;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PathRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

final class UpdateExtensionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('typo3:extensions:update')
            ->setDescription('Check and update installed TYPO3 extensions for a target TYPO3 version')
            ->addArgument('version', InputArgument::REQUIRED, 'TYPO3 version constraint to check against, e.g. ^13.4')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Only show compatibility table, do not modify anything')
            ->setHelp(
                <<<EOT
Load installed TYPO3 extensions (type: typo3-cms-extension) and check their compatibility
against the given target TYPO3 version.

<options=bold,underscore>Features:</>
 * Show available updates (major and minor) compatible with the current TYPO3 version
 * Show extension compatibility for the target version of TYPO3
 * Bump version of local packages in required section (interactive)
 * Update packages to most recent compatible version (interactive)

Use --dry-run to only display the compatibility table without making changes.

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer(true, true);
        $rootPackage = $composer->getPackage();
        $io = new SymfonyStyle($input, $output);
        $installedRepository = $composer->getRepositoryManager()->getLocalRepository();
        $remoteRepositories = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
        $isDryRun = (bool)$input->getOption('dry-run');
        $targetConstraint = $input->getArgument('version');

        $core = $installedRepository->findPackage('typo3/cms-core', '*');

        if (!$core) {
            throw new \RuntimeException('Package typo3/cms-core is not installed. Please run "composer install"');
        }

        $localPackages = [$rootPackage];
        foreach ($remoteRepositories->getRepositories() as $repository) {
            if ($repository instanceof PathRepository) {
                $localPackages = array_merge($localPackages, $repository->getPackages());
            }
        }

        $requiredPackages = [];
        /** @var Package $package */
        foreach ($localPackages as $package) {
            /** @var Link $require */
            foreach ($package->getRequires() + $package->getDevRequires() as $require) {
                $requiredPackages[$require->getTarget()][$package->getName()] = $package;
            }
        }

        try {
            $targetCore = $this->getTargetVersion($core, $targetConstraint, $remoteRepositories);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $isUpgrade = version_compare($targetCore->getVersion(), $core->getVersion(), 'gt');
        $rootDevRequires = array_keys($rootPackage->getDevRequires());

        $coreVersion = $core->getPrettyVersion();
        $progressBar = $this->getProgressBar($requiredPackages, $io);
        $rows = [];
        $requirePackageCommands = [];
        $requireDevPackageCommands = [];
        $processedPackages = [];

        foreach ($requiredPackages as $packageName => $requiredBy) {
            $package = $installedRepository->findPackage($packageName, '*');
            if ($package && $package->getType() === 'typo3-cms-extension') {
                $progressBar->setMessage($package->getName(), 'name');
                $progressBar->advance();
                $version = $this->getLatestCompatibleVersion($package, $installedRepository, $remoteRepositories);

                $newVersionAvailable = version_compare($package->getVersion(), $version->getVersion(), 'lt');
                $processedPackages[$version->getName()] = [
                    'name' => $version->getName(),
                    'version-installed' => $package->getPrettyVersion(),
                    'version-recommended' => $newVersionAvailable ? $version->getPrettyVersion() : null,
                    'compatible-with-current' => $this->isCompatibleWithCore($version, $core->getVersion(), $installedRepository),
                ];

                if ($newVersionAvailable) {
                    $packageSpec = $version->getName() . ':^' . $version->getPrettyVersion();
                    if (in_array($version->getName(), $rootDevRequires, true)) {
                        $requireDevPackageCommands[] = $packageSpec;
                    } else {
                        $requirePackageCommands[] = $packageSpec;
                    }
                }

                if ($isUpgrade) {
                    $latestCompatibleForTarget = $this->getLatestCoreCompatibleVersion($package, $installedRepository, $remoteRepositories, $targetConstraint)->getPrettyVersion();
                    $processedPackages[$version->getName()]['compatible-with-next'] = [
                        'installed' => $this->isCompatibleWithCore($package, $targetConstraint, $installedRepository),
                        'new' => $this->isCompatibleWithCore($version, $targetConstraint, $installedRepository),
                        'compatible-with-target' => version_compare($package->getPrettyVersion(), $latestCompatibleForTarget, 'lt') ? $latestCompatibleForTarget : '',
                    ];
                }
            }
        }

        foreach ($processedPackages as $package) {
            $row = [
                $package['name'],
                $package['version-installed'],
                $package['version-recommended'] ? '<fg=green>' . $package['version-recommended'] . '</>' : '-',
                $package['compatible-with-current'] instanceof Package ? '✅' : '❌',
            ];

            if ($isUpgrade) {
                $nextVersionForTarget = $package['compatible-with-next']['compatible-with-target'];

                if ($package['compatible-with-next']['new']) {
                    $next = '⬆️ Update to ' . $package['compatible-with-next']['new']->getPrettyVersion() . ' required';
                } else {
                    $next = '❌ ' . ($nextVersionForTarget ? 'version ' . $nextVersionForTarget . ' would be compatible ' : '');
                }

                $row[] = $package['compatible-with-next']['installed'] ? '✅' : $next;
            }

            $rows[] = $row;
        }

        $progressBar->setMessage('Done!');
        $progressBar->setMessage('', 'name');
        $progressBar->finish();
        $io->writeln('');
        $tableHeader = ['Package', 'Version', 'Latest compatible version', $coreVersion];
        if ($isUpgrade) {
            $tableHeader[] = $targetConstraint . ' (' . $targetCore->getPrettyVersion() . ')';
        }

        $io->table($tableHeader, $rows);
        $io->writeln('<options=bold,underscore>Legend:</>');
        $io->writeln('✅ = is compatible  ❌ = not compatible  ⬆️= compatible after update to latest compatible version');

        if ($isDryRun) {
            return Command::SUCCESS;
        }

        $question = new ConfirmationQuestion('Proceed with updating extensions? [Y/n] ', true);
        if (!$io->askQuestion($question)) {
            return Command::SUCCESS;
        }

        // Track original file contents for rollback on failure
        $modifiedFiles = [];

        foreach ($processedPackages as $packageName => $package) {
            if ($package['version-recommended']) {
                /** @var CompletePackage $requiredBy */
                foreach ($requiredPackages[$packageName] as $requiredBy) {
                    if (!($requiredBy instanceof RootPackage)) {
                        $filePath = $requiredBy->getDistUrl() . '/composer.json';
                        $composerJson = new JsonFile($filePath);
                        $updateRequired = $composerJson->read();

                        if ($updateRequired['require'][$packageName] === '*') {
                            continue;
                        }

                        $question = new ConfirmationQuestion('Bump ' . $packageName . ' version in required section of ' . $requiredBy->getName() . ' to version ' . $package['version-recommended'] . '? [Y/n] ', true);
                        $answer = $io->askQuestion($question);

                        if ($answer) {
                            if (!isset($modifiedFiles[$filePath])) {
                                $modifiedFiles[$filePath] = $composerJson->read();
                            }
                            try {
                                $updateRequired['require'][$packageName] = '^' . $package['version-recommended'];
                                $composerJson->write($updateRequired);
                            } catch (\RuntimeException $exception) {
                                $this->restoreFiles($modifiedFiles);
                                $io->error($exception->getMessage());
                                return Command::FAILURE;
                            }
                        }
                    }
                }
            }
        }

        if (!empty($requirePackageCommands)) {
            $io->info(['To update TYPO3 extensions (require) run: ', 'composer req ' . implode(' ', $requirePackageCommands)]);

            $question = new ConfirmationQuestion('Run the require command shown above? [Y/n] ', true);
            if ($io->askQuestion($question)) {
                $application = new Application();
                $arrayInput = new ArrayInput(['command' => 'require', 'packages' => $requirePackageCommands]);
                $exitCode = $application->run($arrayInput, $output);

                if ($exitCode) {
                    $this->restoreFiles($modifiedFiles);
                    $this->getIO()->error('Failed to update TYPO3 extensions. All composer.json changes have been reverted.');
                    return Command::FAILURE;
                }
            }
        }

        if (!empty($requireDevPackageCommands)) {
            $io->info(['To update TYPO3 extensions (require-dev) run: ', 'composer req --dev ' . implode(' ', $requireDevPackageCommands)]);

            $question = new ConfirmationQuestion('Run the require --dev command shown above? [Y/n] ', true);
            if ($io->askQuestion($question)) {
                $application = new Application();
                $arrayInput = new ArrayInput(['command' => 'require', 'packages' => $requireDevPackageCommands, '--dev' => true]);
                $exitCode = $application->run($arrayInput, $output);

                if ($exitCode) {
                    $this->restoreFiles($modifiedFiles);
                    $this->getIO()->error('Failed to update TYPO3 dev extensions. All composer.json changes have been reverted.');
                    return Command::FAILURE;
                }
            }
        }

        return Command::SUCCESS;
    }

    public function loadPackageVersions(PackageInterface $package, CompositeRepository $remoteRepositories): array
    {
        $versionParser = new VersionParser();
        $packagesToLoad = [];
        $packagesToLoad[$package->getName()] = $versionParser->parseConstraints('>' . $package->getVersion());

        return $remoteRepositories->loadPackages($packagesToLoad, ['stable' => BasePackage::STABILITY_STABLE], []);
    }

    private function getLatestCompatibleVersion(PackageInterface $currentPackageVersion, InstalledRepositoryInterface $installedRepository, CompositeRepository $remoteRepositories): PackageInterface
    {
        $versions = $this->loadPackageVersions($currentPackageVersion, $remoteRepositories);

        /** @var Package $version */
        foreach ($versions['packages'] as $version) {
            $requiredPackages = $version->getRequires() + $version->getDevRequires();
            $compatibleVersion = true;

            foreach ($requiredPackages as $package) {
                $requiredPackage = $installedRepository->findPackage($package->getTarget(), '*');

                if ($requiredPackage && ($requiredPackage->getType() === 'typo3-cms-framework' || $requiredPackage->getType() === 'typo3-cms-extension')) {
                    $versionParser = new VersionParser();
                    $requiredConstraint = $versionParser->parseConstraints($requiredPackage->getVersion());
                    if (!$requiredConstraint->matches($package->getConstraint())) {
                        $compatibleVersion = false;
                    }
                }
            }

            if ($compatibleVersion) {
                return $version;
            }
        }

        return $currentPackageVersion;
    }

    private function getLatestCoreCompatibleVersion(PackageInterface $currentPackageVersion, InstalledRepositoryInterface $installedRepository, CompositeRepository $remoteRepositories, string $targetConstraint): PackageInterface
    {
        $versions = $this->loadPackageVersions($currentPackageVersion, $remoteRepositories);

        /** @var Package $version */
        foreach ($versions['packages'] as $version) {
            if ($this->isCompatibleWithCore($version, $targetConstraint, $installedRepository)) {
                return $version;
            }
        }

        return $currentPackageVersion;
    }

    private function isCompatibleWithCore(PackageInterface $packageVersion, string $constraint, InstalledRepositoryInterface $installedRepository): ?PackageInterface
    {
        $requiredPackages = $packageVersion->getRequires() + $packageVersion->getDevRequires();
        $compatibleVersion = true;

        foreach ($requiredPackages as $package) {
            $requiredPackage = $installedRepository->findPackage($package->getTarget(), '*');

            if ($requiredPackage && $requiredPackage->getType() === 'typo3-cms-framework') {
                $versionParser = new VersionParser();
                $requiredConstraint = $versionParser->parseConstraints($constraint);
                if (!$requiredConstraint->matches($package->getConstraint())) {
                    $compatibleVersion = false;
                }
            }
        }

        if ($compatibleVersion) {
            return $packageVersion;
        }

        return null;
    }

    private function getProgressBar(array $units, SymfonyStyle $io): ProgressBar
    {
        ProgressBar::setFormatDefinition('packages', ' %current%/%max% -- %message% %name%');
        $progressBar = $io->createProgressBar(count($units));
        $progressBar->setFormat('packages');
        $progressBar->setMessage('Loading packages ...');
        $progressBar->setMessage('', 'name');
        $progressBar->start();

        return $progressBar;
    }

    /**
     * @param array<string, array> $modifiedFiles Map of file path => original parsed content
     */
    private function restoreFiles(array $modifiedFiles): void
    {
        foreach ($modifiedFiles as $filePath => $originalData) {
            (new JsonFile($filePath))->write($originalData);
        }
    }

    private function getTargetVersion(BasePackage $core, string $targetConstraint, CompositeRepository $remoteRepositories): BasePackage
    {
        $targetCoreVersion = $remoteRepositories->findPackage('typo3/cms-core', $targetConstraint);
        if (!$targetCoreVersion) {
            throw new \RuntimeException('No target version found for constraint ' . $targetConstraint);
        }

        if (version_compare($targetCoreVersion->getVersion(), $core->getVersion(), 'lt')) {
            throw new \RuntimeException('The given constraint ' . $targetConstraint . ' (selected ' . $targetCoreVersion->getVersion() . ')' . ' resolves to a version older than the installed version ' . $core->getVersion() . '. Please pick a newer version!');
        }

        return $targetCoreVersion;
    }
}
