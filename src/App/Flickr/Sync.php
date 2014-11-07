<?php

namespace Itscaro\App\Flickr;

use Itscaro\App\Application;
use Itscaro\Service\Flickr\Client;
use Itscaro\Service\Flickr\ClientAbstract;
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
use Zend\Http\Client as Client2;
use ZendOAuth\Consumer;
use ZendOAuth\Token\Access;

class Sync extends Command {

    /**
     *
     * @var Input
     */
    protected $_input;

    /**
     *
     * @var Output
     */
    protected $_output;

    /**
     *
     * @var Access
     */
    protected $_accessToken;

    /**
     *
     * @var Client
     */
    protected $_flickrClient;

    /**
     *
     * @var ClientMulti
     */
    protected $_flickrClientMulti;

    /**
     *
     * @var Photo
     */
    protected $_flickrUploader;


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
        $this->_input = $input;
        $this->_output = $output;

        $app = $this->getApplication();
        /* @var $app Application */
        $config = $app->getConfig();

        $settings = $app->getDataStore('store');

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
            $httpClient = new Client2('', $configHttpClient);
            Consumer::setHttpClient($httpClient);

            // Oauth client
            $consumer = new Consumer($configOauth);

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

            $app->setDataStore('store', array(
                'accessToken' => $accessToken
            ));
        } else {
            $this->_accessToken = $settings['accessToken'];

//            $flickr = new \Itscaro\Service\Flickr\Flickr($configOauth, $configHttpClient);
//            $flickr->setAccessToken($settings['accessToken']);

            $this->_flickrClient = $flickrSimple = new Client('https://api.flickr.com/services/rest', $configOauth, $configHttpClient);
            $flickrSimple->setAccessToken($settings['accessToken']);


            $this->_flickrClientMulti = $flickrMulti = new ClientMulti('https://api.flickr.com/services/rest', $configOauth, $configHttpClient);
            $flickrMulti->setAccessToken($settings['accessToken']);

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

            $this->_flickrUploader = $flickrUploader = new Photo($settings['accessToken'], $configOauth, $configHttpClient);

            $errors = array();
            $filesBatch = array();
            $counter = 0;

            if ($filesFound > 0) {
                if ($this->_input->getOption('progess')) {
                    $progressBar = $this->getHelper('progress');
                }

                if (isset($progressBar)) {
                    $progressBar->start($this->_output, $filesFound);
                }

                foreach ($finder as $file) {
                    if (isset($progressBar)) {
                        $progressBar->advance();
                    }
                    $counter++;
                    /* @var $file SplFileInfo */

                    $filesBatch[] = $file;

                    if (count($filesBatch) == 10 || $filesFound == $counter) {

                        $errors += $this->_process($filesBatch);
                        $filesBatch = array();
                    }
                }

                if (isset($progressBar)) {
                    $progressBar->finish();
                }

                $this->_output->writeln('');
            }

//            $id = $flickrUploader->uploadSync($filePath, basename($file), null, "itscaro:app=flickr-sync,itscaro:photo_hash=".  md5_file($file));
//            echo $file . " - " . $id . "\n";
//            $params = $this->_prepareParamsForProcess('by-taken-year', $settings['accessToken']->getParam('user_nsid'), 1);
//            $response = $flickrMulti->dispatch('GET', 'flickr.photosets.getPhotos', $params);
//
//            var_dump($response);
        }

        $output->writeln('<info>Done</info>');
    }

    protected function _getSyncSetId()
    {
//        $result = $flickrMulti->dispatch('GET', 'flickr.photosets.getList', array(
//            'user_id' => $this->_accessToken->getParam('user_nsid')
//        ));

        $params = array(
            'user_id' => $this->_accessToken->getParam('user_nsid')
        );
        $result = $this->_flickrClient->get('flickr.photosets.getList', $params);

        if ($this->_output->isDebug()) {
            $this->_output->writeln(var_export($params, true));
            $this->_output->writeln(var_export($result, true));
        }

        if($result['stat'] == 'ok') {
            foreach($result['photosets']['photoset'] as $_photoset) {
                if ($_photoset['title']['_content'] == "Flickr-Sync") {
                    return $_photoset['title']['id'];
                }
            }
        }

        return null;
    }

    protected function _addToSyncSet(array $photoIds)
    {
        $_photosetNewlyCreated = false;

        foreach($photoIds as $photoId) {
            // Try to get the photoset using to stock synced photos
            if ($this->_photosetId === null) {
                $this->_photosetId = $this->_getSyncSetId();
            }

            // Try to create the photoset using to stock synced photos
            if ($this->_photosetId === null) {
                try {
                    $this->_photosetId = $this->_createSyncSet($photoId);

                    $_photosetNewlyCreated = true;
                } catch(\Exception $e) {
                    $this->_output->writeln("<error>Cannot create photoset: " . $e->getMessage() . "</error>");
                }
            }

            // Try to add the photo to photoset
            if ($this->_photosetId !== null && $_photosetNewlyCreated === false) {
                $params = array(
                    'photoset_id' => $this->_photosetId,
                    'photo_id' => $photoId
                );
                $result = $this->_flickrClient->post('flickr.photosets.addPhoto', $params);

                if ($this->_output->isDebug()) {
                    $this->_output->writeln(var_export($params, true));
                    $this->_output->writeln(var_export($result, true));
                }
            }

    //        $flickrMulti->dispatch('POST', 'flickr.photosets.addPhoto', array(
    //            'photoset_id' => $this->_photosetId,
    //            'photo_id' => $photoId
    //        ));
        }
    }

    protected function _createSyncSet($photoId)
    {
        if ($this->_output->isVeryVerbose()) {
            $this->_output->writeln('>>> flickr.photos.create');
        }

//        $result = $flickrMulti->dispatch('POST', 'flickr.photosets.create', array(
//            'title' => 'Flickr-Sync',
//            'description' => 'All of the photos that are synced with Flickr-Sync are put in this Photo Set',
//            'primary_photo_id' => $photoId
//        ));

        $params = array(
            'title' => 'Flickr-Sync',
            'description' => 'All of the photos that are synced with Flickr-Sync are put in this Photo Set',
            'primary_photo_id' => $photoId
        );
        $result = $this->_flickrClient->post('flickr.photosets.create', $params);

        if ($this->_output->isDebug()) {
            $this->_output->writeln(var_export($params, true));
            $this->_output->writeln(var_export($result, true));
        }

        if ($result['stat'] == 'ok') {
            return $result['photoset']['id'];
        } else {
            throw new Exception($result['message'], $result['code']);
        }
    }

    protected function _scan($dir)
    {
        $dirRealPath = realpath($dir);

        $this->_output->writeln("<info>Scanning {$dirRealPath}...</info>");

        $finder = new Finder();
        $finder->in($dir)
                ->name('/.*\.(jpg|jpeg|png|gif|tif|tiff)$/');

        return $finder;
    }

    protected function _process(array $files)
    {
        $uploadedPhotoIds = array();
        $errors = array();
        $filesInfo = array();
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $filesInfo[$filePath]['hash'] = md5_file($filePath);
            $filesInfo[$filePath]['requestId'] = $this->_flickrClientMulti->addToQueue('GET', 'flickr.photos.search', array(
                'user_id' => $this->_accessToken->getParam('user_nsid'),
                "machine_tags" => "itscaro:app=flickr-sync,itscaro:photo_hash=" . $filesInfo[$filePath]['hash'],
                "machine_tag_mode" => "all"
            ));
        }

        if ($this->_output->isVerbose()) {
            $this->_output->writeln('>>> Files to check');
            $this->_output->writeln(var_export($filesInfo, 1));
        }

        $result = $this->_flickrClientMulti->dispatchMulti();

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

                    if ($this->_input->getOption('dry-run') === false) {
                        $uploadedPhotoIds[] = $this->_flickrUploader->uploadSync($filePath, $file->getBasename(), $file->getPath(), $tag);

                        if ($this->_output->isVerbose()) {
                            $this->_output->writeln("<comment>File uploaded: {$filePath} (Photo ID: {$id})</comment>");
                        }
                    } else {

                    }
                } else {
                    // File exists on Flickr
                    if ($this->_output->isVeryVerbose()) {
                        $this->_output->writeln("File exists already: {$filePath}");
                    }
                }
            } else {
                $errors[] = $file;
                $this->_output->writeln("<error>Could not verify {$filePath}</error>");
            }
        }

        $this->_addToSyncSet($uploadedPhotoIds);

        return $errors;
    }

}
