<?php

declare(strict_types=1);

namespace Sasso\Composer;

use Composer\Command\BaseCommand;
use Sasso\Exception as SassoException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer sasso:install` — fetch the native library on demand.
 *
 * Only available when this package is installed as a dependency and the plugin
 * is allowed. From a checkout of this repo itself, use `php bin/sasso-install`
 * or `composer run sasso-install` instead (Composer never loads the root
 * package as a plugin).
 *
 * Beyond retrying a failed install, --target lets an image build prefetch a
 * platform other than the one doing the building (an arm64 CI runner baking an
 * x86_64 image, say), which the automatic install cannot infer.
 */
final class InstallBinaryCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('sasso:install')
            ->setDescription('Download the sasso native library for this platform')
            ->addOption(
                'target',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Target triple to fetch instead of the detected one (repeatable, or "all")'
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-download even if already installed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $requested */
        $requested = $input->getOption('target');
        $force = (bool) $input->getOption('force');

        try {
            $installer = new BinaryInstaller(
                fn (string $m) => $output->writeln('<info>sasso:</info> ' . $m)
            );
            $installer->install($requested, $force);
        } catch (SassoException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        return 0;
    }
}
