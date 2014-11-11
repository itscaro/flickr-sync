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
        $this->preExecute($input, $output);

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

            $helper = new Library\Helper($this->_accessToken, $app->getConfig('flickr-oauth'), $app->getConfig('httpClient'));
            $result = $helper->verifyPhotos($this->_input->getArgument(self::ARG_DIRECTORY));

            $diffFlickrvsLocal = $result['diffFlickrvsLocal'];
            $this->_output->writeln('Photos exists on Flickr but not locally: ' . count($diffFlickrvsLocal));
            $this->_logger->debug('Photos exists on Flickr but not locally', array('diff' => $diffFlickrvsLocal));
            if ($this->_output->isVerbose()) {
                $this->_output->writeln(var_export($diffFlickrvsLocal, 1));
            }

            $diffLocalvsFlickr = $result['diffLocalvsFlickr'];
            $this->_output->writeln('Photos exists locally but not on Flickr: ' . count($diffLocalvsFlickr));
            $this->_logger->debug('Photos exists locally but not on Flickr', array('diff' => $diffLocalvsFlickr));
            if ($this->_output->isVerbose()) {
                $this->_output->writeln(var_export($diffLocalvsFlickr, 1));
            }

//            
//            foreach ($photoHash as $value) {
//                $photos = $flickr->photoSearch(array(
//                    'user_id' => $this->_accessToken->getParam('user_nsid'),
//                    'machine_tags' => "itscaro:photo_hash=\"{$value}\""
//                ));
//            }
        }

        $this->_output->writeln('<info>Done in ' . round((microtime(1) - $startTime), 1) . ' seconds</info>');
        $this->postExecute($input, $output, array('startTime' => $startTime));
    }

}
