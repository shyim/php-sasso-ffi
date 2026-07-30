<?php

declare(strict_types=1);

namespace Sasso\Composer;

use Composer\Command\BaseCommand;
use Sasso\Downloader;
use Sasso\Exception as SassoException;
use Sasso\Platform;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `composer sasso:install` — fetch the native library on demand.
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
            $targets = $this->resolveTargets($requested);
        } catch (SassoException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return 1;
        }

        $downloader = new Downloader(fn (string $m) => $output->writeln('<info>sasso:</info> ' . $m));
        $failed = 0;

        foreach ($targets as $target) {
            try {
                $path = $downloader->install($target, Platform::VERSION, $force);
                $output->writeln(sprintf('<info>sasso:</info> %s ready at %s', $target, $path));
            } catch (SassoException $e) {
                $output->writeln(sprintf('<error>sasso: %s failed: %s</error>', $target, $e->getMessage()));
                $failed++;
            }
        }

        return $failed === 0 ? 0 : 1;
    }

    /**
     * @param  list<string> $requested
     * @return list<string>
     */
    private function resolveTargets(array $requested): array
    {
        if ($requested === []) {
            return [Platform::target()];
        }

        if (in_array('all', $requested, true)) {
            return Platform::knownTargets();
        }

        $known = Platform::knownTargets();
        foreach ($requested as $target) {
            if (!in_array($target, $known, true)) {
                throw new SassoException(sprintf(
                    "Unknown target \"%s\". Available targets:\n  %s",
                    $target,
                    implode("\n  ", $known),
                ));
            }
        }

        return array_values($requested);
    }
}
