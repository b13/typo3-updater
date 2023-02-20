<?php

declare(strict_types=1);

namespace B13\Typo3Updater\Command;

use Composer\Command\BaseCommand;
use Composer\Console\Application;
use Composer\Console\Input\InputArgument;
use Composer\InstalledVersions;
use Composer\Package\Link;
use Composer\Package\Package;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RootPackageRepository;
use Composer\Semver\Constraint\Constraint;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use function Deployer\writeln;

final class UpdateCommand extends BaseCommand
{
    protected OutputInterface $output;

    protected function configure()
    {
        $this
            ->setName('typo3:update')
            ->setDescription('Update the TYPO3 installation')
            ->addArgument('version', InputArgument::OPTIONAL, 'TYPO3 target version')
            ->addOption('--dry-run', null, InputOption::VALUE_NONE, 'Show available updates for packages')
            ->setHelp(
                <<<EOT
Deletes all files and folders created/downloaded by "composer tdk:*" commands. 
EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('dry-run')) {
            $this->getIO()->warning('Starting dry-run of TYPO3 update! This does not modify the composer.json ("composer update typo3/cms-* -W")');
        }

        $application = new Application();
        $application->setAutoExit(false);

        // TYPO3 core packages update. Command: "composer update typo3/cms-* -W"
        // See docs: https://getcomposer.org/doc/03-cli.md#update-u-upgrade
        if (!$input->getArgument('version')) {
            $arrayInput = new ArrayInput(array('command' => 'update', 'packages' => ['typo3/cms-*'], '-W' => true, '--dry-run' => $input->getOption('dry-run')));
            $exitCode = $application->run($arrayInput, $output);
            $dryRunText = $input->getOption('dry-run') ? ' <options=bold,underscore>"dry-run"</> ' : ' ';
            $dryRunHint = $input->getOption('dry-run') ? ' To actually update run the command without --dry-run' : '';

            if ($exitCode) {
                $this->getIO()->error('Failed to' . $dryRunText . 'update TYPO3 with all dependencies. See errors above');
                return Command::FAILURE;
            }

            $this->getIO()->write('<fg=green>✓</> TYPO3 update' . $dryRunText . 'successful.' . $dryRunHint);
            return Command::SUCCESS;
        }


        // composer outdated -D <--- only direct dependencies
        // $arrayInput = new ArrayInput(array('command' => 'update', 'packages' => ['typo3/cms-*'], '-W' => true, '--dry-run' => $input->getOption('dry-run')));

        $repository = $this->requireComposer(false, true)->getRepositoryManager()->getLocalRepository();
        $frameworkPackages = $repository->getCanonicalPackages();

        foreach ($frameworkPackages as $package) {
            $rows[] = [$package->getName(), $package->getVersion()];
        }

        $repositories = $this->requireComposer(false, true)->getRepositoryManager()->findPackages('typo3/cms-core', '*');
        $rows[] = ['######################', '###############'];
        foreach ($repositories as $repo) {
            $rows[] = [$repo->getName(), $repo->getVersion()];
        }




//        $frameworkPackages = InstalledVersions::getInstalledPackagesByType('typo3-cms-framework');
//        foreach ($frameworkPackages as $package) {
//            $rows[] = [$package, ''];
//        }

        //        $composer = $this->requireComposer(false, true);
        //        $requirements = $composer->getPackage()->;
        //
        //        $rows = [];
        //        foreach ($requirements as $package) {
        //            $rows[] = [$package->getTarget(), $package->get];
        //        }

        $io->table(['Package name', 'version'], $rows);


        $localRepo = $this->requireComposer(false, true)->getRepositoryManager()->getLocalRepository();
        $rootPkg = $this->requireComposer(false, true)->getPackage();

        if (count($localRepo->getPackages()) === 0 && (count($rootPkg->getRequires()) > 0 || count($rootPkg->getDevRequires()) > 0)) {
            $output->writeln('<warning>No dependencies installed. Try running composer install or update, or use --locked.</warning>');

            return 1;
        }

        $repos[] = $localRepo;

        $platformOverrides = $this->requireComposer(false, true)->getConfig()->get('platform') ?: [];
        $repos[] = new PlatformRepository([], $platformOverrides);

        $installedRepo = new InstalledRepository($repos);
        $constraint = new Constraint('>', '11');

        $dependants = $installedRepo->getDependents('typo3/cms-core', $constraint, true);
        $io->writeln($dependants[0]);
//        foreach ($dependants as $key => $package) {
//            var_dump($package);
//            $io->writeln($package[0]);
//            die('dasdsad');
//        }


//        $installed = new InstalledRepository($composer->getRepositoryManager()->getRepositories());
//        $installed->


        // Update TYPO3 core packages. Command: "composer update typo3/cms-* -W"

        // $exitCode = $application->run($arrayInput, $output);

        $io->info('<fg=green>✓</> ');
        return Command::SUCCESS;

//        $installedPackages = InstalledVersions::getInstalledPackages();
//
//        $composer = $this->requireComposer(false, true);
//
//        $required = $composer->getPackage()->getRequires();
//
//        foreach ($required as $package) {
//            $this->getIO()->write($package->getPrettyConstraint() . ' --- ' . $package->getTarget());
//        }

//        foreach ($installedPackages as $packageName) {
//            // var_dump($packageName);
//            $version = InstalledVersions::getVersion($packageName);
//            $this->getIO()->write($packageName . ' --- ' . $version);
//        }

//        $composer = $this->requireComposer();
//        $package = clone $this->requireComposer()->getPackage();
//        $installedRepo = new InstalledRepository([new RootPackageRepository($package)]);
//        $installedRepo->addRepository($composer->getRepositoryManager()->getLocalRepository());
//
//        foreach ($installedRepo->search('typo3') as $package) {
//            var_dump($package);
//            // $this->getIO()->write($package->getName() . ' --- ' . $package->getVersion());
//        }


        //        $composer = $this->requireComposer(false, true);
//        $repositories = $composer->getRepositoryManager()->getRepositories();
//        $installedRepo = new InstalledRepository([$platformRepo]);
//        $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
//        $installedRepo->addRepository($composer->getRepositoryManager()->getLocalRepository());
//
//        $installed = new InstalledRepository();


    }
}
