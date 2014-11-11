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

class Sync extends CommandAbstract {

    const ARG_DIRECTORY = 'directory';
    const OPT_DRYRUN = 'dry-run';
    const OPT_PROGESS = 'progess';

    protected function configure()
    {
        $this->setName("flickr:sync")
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

            $searchRegEx = '/itscaro\:photohash\=/';
            $tags = $flickr->tagsGetListUser($this->_accessToken->getParam('user_nsid'), $searchRegEx);

            $photoHash = [];
            foreach ($tags as $_tag) {
                $photoHash[] = preg_replace($searchRegEx, '', $_tag);
            }

            $localPhotoHash = [];
            $finder = $this->_scan($directoryToProcess);
            foreach ($finder as $_file) {
                /* @var $_file SplFileInfo */
                $localPhotoHash[$_file->getRealPath()] = md5($_file->getRealPath());
            }

            $diffFlickrvsLocal = array_diff($photoHash, $localPhotoHash);
            $this->_output->writeln('Photos exists on Flickr but not locally: ' . count($diffFlickrvsLocal));
            $this->_logger->debug('Photos exists on Flickr but not locally', array('diff' => $diffFlickrvsLocal));
            //$this->_output->writeln(var_export($diffFlickrvsLocal, 1));

            $diffLocalvsFlickr = array_diff($localPhotoHash, $photoHash);
            $this->_output->writeln('Photos exists locally but not on Flickr: ' . count($diffLocalvsFlickr));
            $this->_logger->debug('Photos exists locally but not on Flickr', array('diff' => $diffLocalvsFlickr));
            //$this->_output->writeln(var_export($diffLocalvsFlickr, 1));
//            foreach ($photoHash as $value) {
//                $photos = $flickr->photoSearch(array(
//                    'user_id' => $this->_accessToken->getParam('user_nsid'),
//                    'machine_tags' => "itscaro:photo_hash=\"{$value}\""
//                ));
//            }
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
                ->name('/.*\.(' . implode('|', $this->_supportedExtensions) . ')$/i');

        return $finder;
    }

}
