<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Application;
use Composer\Console\Input\InputArgument;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\PathRepository;
use Composer\Semver\Constraint\MatchAllConstraint;
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
    protected function configure()
    {
        $this
            ->setName('typo3:update:extensions')
            ->setDescription('Update all TYPO3 extensions')
            ->addArgument('version', InputArgument::OPTIONAL, 'TYPO3 version to upgrade to, e.g. ^11.5')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Show available updates for packages')
            ->setHelp(
                <<<EOT
Load installed TYPO3 extensions (type: typo3-cms-extension) and check their compatability
in conjunction with the currently installed TYPO3 version.

<options=bold,underscore>Features:</>
 * Show available updates (major and minor) compatible with the current TYPO3 version
 * Show extension compatability for the target version of TYPO3 (if 'version' argument is set)

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->requireComposer(true, true);
        $rootPackage = $composer->getPackage();
        $io = new SymfonyStyle($input, $output);
        // @todo: Disable plugins and scripts any good?!
        $installedRepository = $this->requireComposer(true, true)->getRepositoryManager()->getLocalRepository();
        $remoteRepositories = new CompositeRepository($this->requireComposer(true, true)->getRepositoryManager()->getRepositories());

        $core = $installedRepository->findPackage('typo3/cms-core', '*');

        if (!$core) {
            throw new \RuntimeException('Package typo3/cms-core not installed. Please run "composer install"');
        }

        $localPackages = [$rootPackage];
        foreach ($remoteRepositories->getRepositories() as $repository) {
            if($repository instanceof PathRepository) {
                $localPackages = array_merge($localPackages, $repository->getPackages());
            }
        }

        $requiredPackages = [];
        /** @var Package $package */
        foreach ($localPackages as $package) {
            /** @var Link $require */
            foreach ($package->getRequires() as $require) {
                $requiredPackages[$require->getTarget()][$package->getName()] = $package;
            }
        }

        try {
            $targetCore = $this->getTargetVersion($core, $input, $remoteRepositories);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $coreVersion = $core->getVersion();
        $packages = $installedRepository->getPackages();
        $progressBar = $this->getProgressBar($requiredPackages, $io);
        $rows = [];
        $requirePackageCommands = [];
        $processedPackages = [];

        foreach ($requiredPackages as $packageName => $requiredBy) {
            $package = $installedRepository->findPackage($packageName,'*');
            if($package && $package->getType() === 'typo3-cms-extension') {
                $progressBar->setMessage($package->getName(), 'name');
                $progressBar->advance();
                $version = $this->getLatestCompatibleVersion($package, $installedRepository, $remoteRepositories);

                $newVersionAvailable =  version_compare($package->getVersion(), $version->getVersion(), 'lt');
                //$row = [$version->getName(), $package->getPrettyVersion(), $flag, '✅', ];
                $processedPackages[$version->getName()] = [
                    'name' => $version->getName(),
                    'version-installed' => $package->getPrettyVersion(),
                    'version-recommended' => $newVersionAvailable ? $version->getPrettyVersion() : null,
                    'compatible-with-current' => $this->isCompatibleWithCore($version, $core->getPrettyVersion(), $installedRepository)
                ];

                if($newVersionAvailable) {
                    $requirePackageCommands[] = $version->getName() . ':^' . $version->getPrettyVersion();
                }

                if ($input->getArgument('version')) {
                    $latestCompatibleForTarget = $this->getLatestCoreCompatibleVersion($package, $installedRepository, $remoteRepositories, $input)->getPrettyVersion();
                    $processedPackages[$version->getName()]['compatible-with-next'] = [
                        'installed' => $this->isCompatibleWithCore($package, $input->getArgument('version'), $installedRepository),
                        'new' => $this->isCompatibleWithCore($version, $input->getArgument('version'), $installedRepository),
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

            if ($input->getArgument('version')) {
                $nextVersionForTarget = $package['compatible-with-next']['compatible-with-target'];


                if($package['compatible-with-next']['new']) {
                    $next = '⛔️Update to ' . $package['compatible-with-next']['new']->getPrettyVersion() . ' required';
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

        if ($input->getArgument('version')) {
            $tableHeader[] = $input->getArgument('version') . ' (' . $targetCore->getFullPrettyVersion() . ')';
        }

        $io->table($tableHeader, $rows);
        $io->writeln('<options=bold,underscore>Legend:</>');
        $io->writeln('✅ = is compatible  ❌ = not compatible  ⛔️ = compatible after update to latest compatible version');

        if(!empty($requirePackageCommands)) {
            $question = new ConfirmationQuestion('Show require command ?', false);
            $answer = $io->askQuestion($question);
            if($answer) {
                $info = ['Updating extensions', 'composer req ' . implode(" \ \n    ", $requirePackageCommands)];
                $io->info($info);

// @todo: run require command and bump version for local packages so constraints are in sync.
//
//                $application = new Application();
//
//                $arrayInput = new ArrayInput(array('command' => 'require', 'packages' => $requirePackageCommands, '-W' => true, '--dry-run' => $input->getOption('dry-run')));
//                $exitCode = $application->run($arrayInput, $output);
//
//                if ($exitCode) {
//                    $this->getIO()->error('Failed to update TYPO3 extensions with all dependencies. See errors above');
//                    return Command::FAILURE;
//                }
            }
        }

        return Command::SUCCESS;
    }

    public function loadPackageVersions(string $packageName, CompositeRepository $remoteRepositories): array
    {
        $packagesToLoad = [];
        $packagesToLoad[$packageName] = new MatchAllConstraint();

        return $remoteRepositories->loadPackages($packagesToLoad, ['stable' => BasePackage::STABILITY_STABLE], []);
    }

    private function getLatestCompatibleVersion(Package $currentPackageVersion, InstalledRepositoryInterface $installedRepository, CompositeRepository $remoteRepositories): Package
    {
        $versions = $this->loadPackageVersions($currentPackageVersion->getName(), $remoteRepositories);

        /** @var Package $version */
        foreach ($versions['packages'] as $version) {
            $requiredPackages = $version->getRequires();
            $compatibleVersion = true;

            foreach ($requiredPackages as $package) {
                // Load package from local/installed repo
                $requiredPackage = $installedRepository->findPackage($package->getTarget(), '*');

                if($requiredPackage && ($requiredPackage->getType() === 'typo3-cms-framework' || $requiredPackage->getType() === 'typo3-cms-extension')) {
                    $versionParser = new VersionParser();
                    $requiredConstraint = $versionParser->parseConstraints($requiredPackage->getVersion());
                    if (!$requiredConstraint->matches($package->getConstraint())) {
                        $compatibleVersion = false;
                    }
                }
            }

            // Return the first (latest) compatible version
            if($compatibleVersion) {
                return $version;
            }
        }

        return $currentPackageVersion;
    }

    private function getLatestCoreCompatibleVersion(Package $currentPackageVersion, InstalledRepositoryInterface $installedRepository, CompositeRepository $remoteRepositories, InputInterface $input): Package
    {
        $versions = $this->loadPackageVersions($currentPackageVersion->getName(), $remoteRepositories);

        /** @var Package $version */
        foreach ($versions['packages'] as $version) {
            $requiredPackages = $version->getRequires();
            $compatibleVersion = true;

            // Return the first (latest) compatible version
            if($this->isCompatibleWithCore($version, $input->getArgument('version'), $installedRepository)) {
                return $version;
            }
        }

        return $currentPackageVersion;
    }

    private function isCompatibleWithCore(Package $packageVersion, string $constraint, InstalledRepositoryInterface $installedRepository): ?Package
    {
        $requiredPackages = $packageVersion->getRequires();
        $compatibleVersion = true;

        foreach ($requiredPackages as $package) {
            // Load package from local/installed repo
            $requiredPackage = $installedRepository->findPackage($package->getTarget(), '*');

            if($requiredPackage && $requiredPackage->getType() === 'typo3-cms-framework') {
                $versionParser = new VersionParser();
                $requiredConstraint = $versionParser->parseConstraints($constraint);
                if (!$requiredConstraint->matches($package->getConstraint())) {
                    $compatibleVersion = false;
                }
            }
        }

        // Return the first (latest) compatible version
        if($compatibleVersion) {
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

    private function getTargetVersion(BasePackage $core, InputInterface $input, CompositeRepository $remoteRepositories): ?BasePackage
    {
        if ($input->getArgument('version')) {
            $targetCoreVersion = $remoteRepositories->findPackage('typo3/cms-core', $input->getArgument('version'));
            if (!$targetCoreVersion) {
                throw new \RuntimeException('No target version found for constraint ' . $input->getArgument('version'));
            }

            if (version_compare($targetCoreVersion->getVersion(), $core->getVersion(), 'le')) {
                throw new \RuntimeException('The given constraint ' . $input->getArgument('version') . ' (selected ' . $targetCoreVersion->getVersion() . ')' . ' is not useful to compare with the installed version ' . $core->getVersion() . '. Please pick a newer version!');
            }

            return $targetCoreVersion;
        }

        return null;
    }
}
