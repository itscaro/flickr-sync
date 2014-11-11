<?php

namespace Itscaro\App\Flickr;

use Exception;
use Itscaro\App\Application;
use Itscaro\App\Flickr\Library\Authenticate;
use Itscaro\Service\Flickr\Client;
use Itscaro\Service\Flickr\ClientMulti;
use Itscaro\Service\Flickr\Photo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Upload extends CommandAbstract {

    const WAIT_BETWEEN_BATCH = 2500; // in µ second
    const ARG_DIRECTORY = 'directory';
    const OPT_DRYRUN = 'dry-run';
    const OPT_PROGESS = 'progess';

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

    /**
     *
     * @var  
     */
    protected $_photosetId;

    protected function configure()
    {
        $this->setName("flickr:upload")
                ->setDescription("")
                ->setDefinition(array())
                ->setHelp(<<<EOT
EOT
                )
                ->addOption(self::OPT_PROGESS, 'p', InputOption::VALUE_OPTIONAL, 'Show progess bar', true)
                ->addOption(self::OPT_DRYRUN, 'd', InputOption::VALUE_OPTIONAL, 'Dry run, do not upload to Flickr', false)
                ->addArgument(self::ARG_DIRECTORY, InputArgument::OPTIONAL, 'Directory to scan', getcwd());
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

            $this->_flickrClient = $flickrSimple = new Client('https://api.flickr.com/services/rest', $app->getConfig('flickr-oauth'), $app->getConfig('httpClient'));
            $flickrSimple->setAccessToken($settings['accessToken']);

            $this->_flickrClientMulti = $flickrMulti = new ClientMulti('https://api.flickr.com/services/rest', $app->getConfig('flickr-oauth'), $app->getConfig('httpClient'));
            $flickrMulti->setAccessToken($settings['accessToken']);

            $finder = $this->scan($this->_input->getArgument(self::ARG_DIRECTORY));
            $filesFound = count($finder);

            $this->_output->writeln("<info>Found {$filesFound} photos</info>");

            $this->_flickrUploader = new Photo($settings['accessToken'], $app->getConfig('flickr-oauth'), $app->getConfig('httpClient'));

            $errors = array();
            $filesBatch = array();
            $counter = 0;

            if ($filesFound > 0) {
                if ($this->_input->getOption(self::OPT_PROGESS)) {
                    $progressBar = $this->getHelper('progress');
                }

                if (isset($progressBar)) {
                    $progressBar->start($this->_output, $filesFound);
                }

                foreach ($finder as $file) {
                    $counter++;
                    /* @var $file SplFileInfo */

                    $filesBatch[] = $file;

                    if (count($filesBatch) == 10 || $filesFound == $counter) {

                        $errors += $this->process($filesBatch);
                        $filesBatch = array();

                        usleep(self::WAIT_BETWEEN_BATCH);
                    }

                    if (isset($progressBar)) {
                        $progressBar->setCurrent($counter);
                    }
                }

                if (isset($progressBar)) {
                    $progressBar->finish();
                }

                $this->_output->writeln('');
            }
        }

        $this->_output->writeln('<info>Done in ' . round((microtime(1) - $startTime), 1) . ' seconds</info>');
        $this->_postExecute($input, $output, array('startTime' => $startTime));
    }

    private function getSyncSetId($photosetName)
    {
//        $result = $flickrMulti->dispatch('GET', 'flickr.photosets.getList', array(
//            'user_id' => $this->_accessToken->getParam('user_nsid')
//        ));

        $params = array(
            'user_id' => $this->_accessToken->getParam('user_nsid')
        );
        $result = $this->_flickrClient->get('flickr.photosets.getList', $params);

        $this->_logger->debug('>>> flickr.photos.getList', array('params' => $params, 'result' => $result));
        if ($this->_output->isDebug()) {
            $this->_output->writeln(var_export($params, true));
            $this->_output->writeln(var_export($result, true));
        }

        if ($result['stat'] == 'ok') {
            foreach ($result['photosets']['photoset'] as $_photoset) {
                if ($_photoset['title']['_content'] == $photosetName) {
                    return $_photoset['id'];
                }
            }
        }

        return null;
    }

    private function addToSyncSet(array $photoIds)
    {
        foreach ($photoIds as $photoId => $photoInfo) {
            $_photosetNewlyCreated = false;

            // Try to get the photoset using to stock synced photos
            if ($this->_photosetId === null) {
                $this->_photosetId = $this->getSyncSetId("Flickr-Sync");
            }

            // Try to create the photoset using to stock synced photos
            if ($this->_photosetId === null) {
                try {
                    $this->_photosetId = $this->createSyncSet("Flickr-Sync", $photoId);

                    $_photosetNewlyCreated = true;
                } catch (Exception $e) {
                    $this->_output->writeln("<error>Cannot create photoset: " . $e->getMessage() . "</error>");
                }
            }

            // Try to add the photo to photoset
            if ($this->_photosetId !== null && $_photosetNewlyCreated === false) {
                $this->_logger->info('>>> flickr.photosets.addPhoto');
                if ($this->_output->isVeryVerbose()) {
                    $this->_output->writeln('>>> flickr.photosets.addPhoto');
                }

                //@todo switch to clientmulti
                $params = array(
                    'photoset_id' => $this->_photosetId,
                    'photo_id' => $photoId
                );
                $result = $this->_flickrClient->post('flickr.photosets.addPhoto', $params);

                $this->_logger->debug('<<< flickr.photosets.addPhoto', array('params' => $params, 'result' => $result));
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

    private function createSyncSet($photosetName, $photoId)
    {
        $this->_logger->info('>>> flickr.photosets.create');
        if ($this->_output->isVeryVerbose()) {
            $this->_output->writeln('>>> flickr.photosets.create');
        }

//        $result = $flickrMulti->dispatch('POST', 'flickr.photosets.create', array(
//            'title' => 'Flickr-Sync',
//            'description' => 'All of the photos that are synced with Flickr-Sync are put in this Photo Set',
//            'primary_photo_id' => $photoId
//        ));

        $params = array(
            'title' => $photosetName,
            'description' => 'All of the photos that are synced with Flickr-Sync are put in this Photo Set',
            'primary_photo_id' => $photoId
        );
        $result = $this->_flickrClient->post('flickr.photosets.create', $params);

        $this->_logger->debug('<<< flickr.photos.create', array('params' => $params, 'result' => $result));
        if ($this->_output->isDebug()) {
            $this->_output->writeln(var_export($params, true));
            $this->_output->writeln(var_export($result, true));
        }

        if ($result['stat'] == 'ok') {
            return $result['photoset']['id'];
        } else {
            $this->_logger->error('!!! flickr.photosets.create', array('message' => $result['message'], 'code' => $result['code']));
            throw new Exception($result['message'], $result['code']);
        }
    }

    private function scan($dir)
    {
        $dirRealPath = realpath($dir);

        $this->_output->writeln("<info>Scanning {$dirRealPath}...</info>");

        $finder = new Finder();
        $finder->setAdapter('php')
                ->in($dir)
                ->name('/.*\.(' . implode('|', $this->_supportedExtensions) . ')$/i');

        return $finder;
    }

    private function process(array $files)
    {        
        $uploadedPhotoIds = array();
        $errors = array();
        $filesInfo = array();
        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            $filesInfo[$filePath]['hash'] = hash_file('md5', $filePath);
            $filesInfo[$filePath]['machine_tags'] = array(
                "itscaro:app=flickr-sync",
                "itscaro:photo_hash=" . $filesInfo[$filePath]['hash'],
                "itscaro:directory_origin=" . $this->_sanitizeTag(dirname($filePath)),
                "itscaro:directory=" . $this->_sanitizeTag(basename(dirname($filePath))),
            );
            $filesInfo[$filePath]['requestId'] = $this->_flickrClientMulti->addToQueue('GET', 'flickr.photos.search', array(
                'user_id' => $this->_accessToken->getParam('user_nsid'),
                "machine_tags" => implode(',', $filesInfo[$filePath]['machine_tags']),
                "machine_tag_mode" => "all"
            ));
        }

        if ($this->_output->isVeryVerbose()) {
            $this->_output->writeln('>>> Files to check');
            $this->_output->writeln(var_export($filesInfo, 1));
        }

        $this->_logger->info('>>> flickr.photos.search');
        $result = $this->_flickrClientMulti->dispatchMulti();
        $this->_logger->debug('<<< flickr.photos.search', array('result' => $result));

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
                    $tag = implode(' ', $filesInfo[$filePath]['machine_tags']);

                    if ($this->_input->getOption(self::OPT_DRYRUN) === false) {
                        $this->_logger->info('Uploading file', array('file' => $filePath, 'fileInfo' => $filesInfo[$filePath]));

                        try {
                            $id = $this->_flickrUploader->uploadSync($filePath, $file->getBasename(), $file->getPath(), $tag);
                            $uploadedPhotoIds[$id] = $filesInfo[$filePath];

                            $this->_logger->info("Photo ID {$id}: {$filePath}");
                            if ($this->_output->isVerbose()) {
                                $this->_output->writeln("<comment>Photo ID {$id}: {$filePath}</comment>");
                            }
                        } catch (Exception $e) {
                            $this->_logger->error('!!! Upload failed', array('message' => $e->getMessage(), 'code' => $e->getCode()));
                        }
                    } else {
                        // Dry-run do not upload to Flickr
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

        $this->addToSyncSet($uploadedPhotoIds);

        return $errors;
    }

}
