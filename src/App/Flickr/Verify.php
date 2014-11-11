<?php

namespace Itscaro\App\Flickr;

use Itscaro\App\Application;
use Itscaro\Service\Flickr\Client;
use Itscaro\Service\Flickr\ClientMulti;
use Itscaro\Service\Flickr\Photo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Zend\Http\Client as ZendHttpClient;
use ZendOAuth\Consumer;
use ZendOAuth\Token\Access;

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
        $config = $app->getConfig();
        $settings = $app->getDataStore('store');

        $configOauth = array(
            'siteUrl' => $config['flickr']['oauth']['siteUrl'],
            'consumerKey' => $config['flickr']['oauth']['consumerKey'],
            'consumerSecret' => $config['flickr']['oauth']['consumerSecret'],
        );
        $configHttpClient = array(
            'adapter' => 'Zend\Http\Client\Adapter\Curl',
            'sslverifypeer' => false
        );

        if (!isset($settings['accessToken'])) {
            $authenticate = new Library\Authenticate($input, $output, $this->_logger);
            $accessToken = $authenticate->authenticate($configOauth, $configHttpClient);

            $app->setDataStore('store', array(
                'accessToken' => $accessToken
            ));
        } else {
            $this->_accessToken = $settings['accessToken'];

            $directoryToProcess = realpath($this->_input->getArgument(self::ARG_DIRECTORY));

            $flickr = new \Itscaro\Service\Flickr\Flickr($this->_accessToken, $configOauth, $configHttpClient);
                            
            // Scan for all sub-dir
            $finder = $this->_scan($directoryToProcess);

            $this->_logger->info(sprintf('Found %d sub-directories', $finder->count()));

            // Try to find the asked directory only
            if (count($finder) == 0) {
                $finder = $this->_scan(dirname($directoryToProcess));
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
                
                $params = array(
                    'user_id' => $this->_accessToken->getParam('user_nsid'),
                    "machine_tags" => implode(',', array(
                        'itscaro:directory_origin=' . $this->_sanitizeTag($_directory)
                    )),
                    "machine_tag_mode" => "all",
                    'per_page' => 1000
                );
                $photos = $flickr->photoSearchAll($params);

                $this->_logger->debug('photoSearchAll', array('params' => $params));
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

    protected function _scan($dir)
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
