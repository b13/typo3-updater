<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Input\InputArgument;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Package\Version\VersionParser;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Semver\Constraint\MatchAllConstraint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
        $io = new SymfonyStyle($input, $output);
        // @todo: Disable plugins and scripts any good?!
        $installedRepository = $this->requireComposer(true, true)->getRepositoryManager()->getLocalRepository();
        $remoteRepositories = new CompositeRepository($this->requireComposer(true, true)->getRepositoryManager()->getRepositories());
        $core = $installedRepository->findPackage('typo3/cms-core', '*');

        if (!$core) {
            throw new \RuntimeException('Package typo3/cms-core not installed. Please run "composer install"');
        }

        try {
            $targetCore = $this->getTargetVersion($core, $input, $remoteRepositories);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $coreVersion = $core->getVersion();
        $packages = $installedRepository->getPackages();
        $progressBar = $this->getProgressBar($packages, $io);
        foreach ($packages as $package) {
            if($package->getType() === 'typo3-cms-extension') {
                $progressBar->setMessage($package->getName(), 'name');
                $progressBar->advance();

                $version = $this->getLatestCompatibleVersion($package->getName(), $installedRepository, $remoteRepositories);

                if($version) {
                    $newVersionAvailable = $package->getVersion() !== $version->getVersion();
                    $flag = $newVersionAvailable ? '<fg=green>' . $version->getPrettyVersion() . '</>' : '-';
                    $row = [$version->getName(), $package->getPrettyVersion(), $flag, '✅', ];
                    if ($input->getArgument('version')) {
                        $compatible = $this->isCompatibleWithCore($version, $input->getArgument('version'), $installedRepository);
                        $nextCompatible = $newVersionAvailable && $compatible ? '⛔️️ Update to ' . $compatible->getPrettyVersion() . ' required' : '✅';
                        $row[] = $compatible ? $nextCompatible : '❌';
                    }

                    $rows[] = $row;
                } else {
                    // @todo: double check if this is not used anymore?!
                    $rows[] = [$package->getName(), '❌', $package->getName(), '❌'];
                }
            }
        }

        $progressBar->setMessage('Done!');
        $progressBar->setMessage('', 'name');
        $progressBar->finish();
        $io->writeln('');
        $tableHeader = ['Package', 'version', 'new version', $coreVersion];

        if ($input->getArgument('version')) {
            $tableHeader[] = $input->getArgument('version') . ' (' . $targetCore->getFullPrettyVersion() . ')';
        }

        $io->table($tableHeader, $rows);

        return Command::SUCCESS;
    }

    public function loadPackageVersions(string $packageName, CompositeRepository $remoteRepositories): array
    {
        $packagesToLoad = [];
        $packagesToLoad[$packageName] = new MatchAllConstraint();

        return $remoteRepositories->loadPackages($packagesToLoad, ['stable' => BasePackage::STABILITY_STABLE], []);
    }

    private function getLatestCompatibleVersion(string $packageName, InstalledRepositoryInterface $installedRepository, CompositeRepository $remoteRepositories): ?Package
    {
        $versions = $this->loadPackageVersions($packageName, $remoteRepositories);

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

        return null;
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
