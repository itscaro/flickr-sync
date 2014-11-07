<?php

namespace Itscaro\App\Flickr;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

class Sync extends Command {

    /**
     *
     * @var \Symfony\Component\Console\Input\Input
     */
    protected $_input;

    /**
     *
     * @var \Symfony\Component\Console\Output\Output
     */
    protected $_output;

    protected function configure()
    {
        $this->setName("flickr:sync")
                ->setDescription("")
                ->setDefinition(array())
                ->setHelp(<<<EOT
EOT
                )->addOption('dry-run', 'd', \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL, 'Dry run, do not upload to Flickr', false)
                ->addArgument('directory', InputArgument::OPTIONAL, 'Directory to scan', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input;
        $this->_output = $output;

        $app = $this->getApplication();
        /* @var $app \Itscaro\App\Application */
        $config = $app->getConfig();

        $settings = $this->_getDataStore();

        $configOauth = array(
            'siteUrl' => 'https://www.flickr.com/services/oauth/',
            'consumerKey' => $config['flickr']['oauth']['consumerKey'],
            'consumerSecret' => $config['flickr']['oauth']['consumerSecret'],
        );
        $configHttpClient = array(
            'adapter' => 'Zend\Http\Client\Adapter\Curl',
            'sslverifypeer' => false
        );

        if (!isset($settings['accessToken'])) {
            // Instantiate a client object
            $httpClient = new \Zend\Http\Client('', $configHttpClient);
            \ZendOAuth\Consumer::setHttpClient($httpClient);

            // Oauth client
            $consumer = new \ZendOAuth\Consumer($configOauth);

            //var_dump($consumer->getRequestTokenUrl());

            $requestToken = $consumer->getRequestToken();
            //var_dump($requestToken->getToken());

            $output->writeln("<info>Please open this URL:</info>");
            $authorizationUrl = $consumer->getRedirectUrl(array(
                'perms' => 'delete'
            ));
            $output->writeln("<info>" . $authorizationUrl . "</info>");

            $helper = $this->getHelper('question');
            $question = new Question\Question('<question>Confirmation code:</question>');
            $response = $helper->ask($input, $output, $question);

            if ($response) {
                $queryData = array(
                    'oauth_token' => $requestToken->getToken(),
                    'oauth_verifier' => $response,
                );
            } else {
                return;
            }

            $accessToken = $consumer->getAccessToken($queryData, $requestToken);

            $this->_setDataStore(array(
                'accessToken' => $accessToken
            ));
        } else {
//            $flickr = new \Itscaro\Service\Flickr\Flickr($configOauth, $configHttpClient);
//            $flickr->setAccessToken($settings['accessToken']);
//
            $flickrMulti = new \Itscaro\Service\Flickr\ClientMulti('https://api.flickr.com/services/rest', $configOauth, $configHttpClient);
            $flickrMulti->setAccessToken($settings['accessToken']);

//            $flickrSimple = new \Itscaro\Service\Flickr\Client('https://api.flickr.com/services/rest', $configOauth, $configHttpClient);
//            $flickrSimple->setAccessToken($settings['accessToken']);
//            $result = $flickrSimple->get('flickr.photosets.getList', array(
//                'user_id' => "10995091@N00"
//            ));
//            var_dump($result);
//
//            $flickrMulti->addToQueue('GET', 'flickr.photosets.getList', array(
//                'user_id' => "10995091@N00"
//            ));
//            $result = $flickrMulti->dispatchMulti();
//            var_dump($result);
//
//            $flickr->photosetGetList("10995091@N00");
//            $result = $flickr->dispatch();
//            var_dump($result);

            $finder = $this->_scan($this->_input->getArgument('directory'));
            $filesFound = count($finder);

            $this->_output->writeln("<info>Found {$filesFound} photos</info>");

            $flickrUploader = new \Itscaro\Service\Flickr\Photo($settings['accessToken'], $configOauth, $configHttpClient);

            $errors = array();
            $filesBatch = array();
            $counter = 0;

            $progressBar = $this->getHelper('progress');
            $progressBar->start($this->_output, $filesFound);
            foreach ($finder as $file) {
                $progressBar->advance();
                $counter++;
                /* @var $file \Symfony\Component\Finder\SplFileInfo */

                $filesBatch[] = $file;

                if (count($filesBatch) == 10 || $filesFound == $counter) {

                    $errors += $this->_process($flickrMulti, $flickrUploader, $filesBatch);
                    $filesBatch = array();
                }
            }
            $progressBar->finish();
            $this->_output->writeln('');

//            $id = $flickrUploader->uploadSync($filePath, basename($file), null, "itscaro:app=flickr-sync,itscaro:photo_hash=".  md5_file($file));
//            echo $file . " - " . $id . "\n";
//            $params = $this->_prepareParamsForProcess('by-taken-year', $settings['accessToken']->getParam('user_nsid'), 1);
//            $response = $flickrMulti->dispatch('GET', 'flickr.photosets.getPhotos', $params);
//
//            var_dump($response);
        }

        $output->writeln('<info>Done</info>');
    }

    protected function _scan($dir)
    {
        $dirRealPath = realpath($dir);

        $this->_output->writeln("<info>Scanning {$dirRealPath}...</info>");

        $finder = new \Symfony\Component\Finder\Finder();
        $finder->in($dir)
                ->name('/.*\.(jpg|jpeg|png|gif|tif|tiff)$/');

        return $finder;
    }

    protected function _process($flickrMulti, $flickrUploader, array $files)
    {
        $errors = array();
        $filesInfo = array();
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $filesInfo[$filePath]['hash'] = md5_file($filePath);
            $filesInfo[$filePath]['requestId'] = $flickrMulti->addToQueue('GET', 'flickr.photos.search', array(
                'user_id' => "10995091@N00",
                "machine_tags" => "itscaro:app=flickr-sync,itscaro:photo_hash=" . $filesInfo[$filePath]['hash'],
                "machine_tag_mode" => "all"
            ));
        }

        if ($this->_output->isVerbose()) {
            $this->_output->writeln('>>> Files to check');
            $this->_output->writeln(var_export($filesInfo, 1));
        }

        $result = $flickrMulti->dispatchMulti();

        if ($this->_output->isVeryVerbose()) {
            $this->_output->writeln('>>> flickr.photos.search');
            $this->_output->writeln(var_export($result, 1));
        }

        foreach ($files as $file) {
            $filePath = $file->getRealPath();

            $requestId = $filesInfo[$filePath]['requestId'];
            if (isset($result[$requestId])) {
                $_result = json_decode($result[$requestId], true);
                if ($_result['photos']['total'] == 0) {
                    // File not found on Flickr
                    $tag = "itscaro:app=flickr-sync itscaro:photo_hash=" . $filesInfo[$filePath]['hash'];

                    if ($this->_input->getOption('dry-run')) {
                    } else {
                        $id = $flickrUploader->uploadAsync($filePath, $file->getBasename(), $file->getPath(), $tag);
                        $this->_output->writeln("<comment>File uploaded: {$filePath} (Photo ID: {$id})</comment>");
                    }
                } else {
                    // File exists on Flickr
                    $this->_output->writeln("File exists already: {$filePath}");
                }
            } else {
                $errors[] = $file;
                $this->_output->writeln("<error>Could not verify {$filePath}</error>");
            }
        }
echo "test";
        return $errors;
    }

    protected function _getDataStore()
    {

        //Define your file path based on the cache one
        $filename = ROOTDIR . '/data/store';

        //Create your own folder in the cache directory
        $fs = new Filesystem();
        try {
            $fs->mkdir(dirname($filename));
        } catch (IOException $e) {
            echo "An error occured while creating your directory";
        }

        if ($fs->exists($filename)) {
            return unserialize(file_get_contents($filename));
        } else {
            return array();
        }
    }

    protected function _setDataStore(array $data)
    {
        //Define your file path based on the cache one
        $filename = ROOTDIR . '/data/store';

        //Create your own folder in the cache directory
        $fs = new Filesystem();
        try {
            $fs->mkdir(dirname($filename));
        } catch (IOException $e) {
            echo "An error occured while creating your directory";
        }

        if (!$fs->exists($filename)) {
            $fs->touch($filename);
        }

        return file_put_contents($filename, serialize($data));
    }

}
