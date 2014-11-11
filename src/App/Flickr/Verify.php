<?php

namespace Itscaro\App\Flickr;

use Itscaro\App\Application;
use Itscaro\App\Flickr\Library\Authenticate;
use Itscaro\Service\Flickr\Flickr;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Verify extends CommandAbstract {

    const ARG_DIRECTORY = 'directory';
    const OPT_DRYRUN = 'dry-run';
    const OPT_PROGESS = 'progess';

    protected function configure()
    {
        $this->setName("flickr:verify")
                ->setDescription("")
                ->setDefinition(array())
                ->setHelp(<<<EOT
EOT
                )
                ->addOption('progess', 'p', InputOption::VALUE_OPTIONAL, 'Show progess bar', true)
                ->addOption('dry-run', 'd', InputOption::VALUE_OPTIONAL, 'Dry run, do not upload to Flickr', false)
                ->addArgument('directory', InputArgument::OPTIONAL, 'Directory to scan', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_preExecute($input, $output);

        $startTime = microtime(1);

        $app = $this->getApplication();
        /* @var $app Application */
        $settings = $app->getDataStore('store');

        if (!isset($settings['accessToken'])) {
            $authenticate = new Authenticate($input, $output, $this->_logger);
            $accessToken = $authenticate->authenticate($app->getConfig('flickr-oauth'), $app->getConfig('httpClient'));

            $app->setDataStore('store', array(
                'accessToken' => $accessToken
            ));
        } else {
            $this->_accessToken = $settings['accessToken'];

            $directoryToProcess = realpath($this->_input->getArgument(self::ARG_DIRECTORY));

            // Scan for all sub-dir
            $finder = $this->scan($directoryToProcess);

            $this->_logger->info(sprintf('Found %d sub-directories', $finder->count()));

            // Try to find the asked directory only
            if (count($finder) == 0) {
                $finder = $this->scan(dirname($directoryToProcess));
                $finder->depth("< 1")
                        ->name(basename($directoryToProcess));

                if ($finder->count() > 0) {
                    $this->_logger->info(sprintf('Found the directory "%s"', $directoryToProcess));
                }
            }

            foreach ($finder as $directory) {
                /* @var $directory SplFileInfo */

                $_directory = $directory->getRealPath();

                $this->_logger->debug(sprintf('Processing directory "%s"', $_directory));

                $helper = new Library\Helper($this->_accessToken, $app->getConfig('flickr-oauth'), $app->getConfig('httpClient'));
                $photos = $helper->verifyPhotosForDirectory($directory);

                $this->_logger->info(vsprintf("Found %d photos on Flickr for the directory '%s'", array(
                    $photos->total,
                    $_directory
                                )
                ));
                $this->_output->writeln(vsprintf("Found %d photos on Flickr for the directory '%s'", array(
                    $photos->total,
                    $_directory
                                )
                ));
            }
        }

        $this->_output->writeln('<info>Done in ' . round((microtime(1) - $startTime), 1) . ' seconds</info>');
        $this->_postExecute($input, $output, array('startTime' => $startTime));
    }

    private function scan($dir)
    {
        $dirRealPath = realpath($dir);

        $this->_output->writeln("<info>Scanning {$dirRealPath}...</info>");

        $finder = new Finder();
        $finder->setAdapter('php')
                ->in($dir)
                ->directories();

        return $finder;
    }

}
