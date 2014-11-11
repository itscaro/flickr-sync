<?php

namespace Itscaro\App\Flickr\Library;

use Itscaro\Service\Flickr\Flickr;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use ZendOAuth\Token\Access;

class Helper {

    protected $_accessToken;
    protected $_optionsOAuth;
    protected $_optionsHttpClient;

    /**
     * List of supported extensions
     * @var array
     */
    protected $_supportedExtensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'tif',
        'tiff'
    );

    public function __construct(Access $accessToken, array $optionsOAuth = array(), array $optionsHttpClient = array())
    {
        $this->_accessToken = $accessToken;
        $this->_optionsOAuth = $optionsOAuth;
        $this->_optionsHttpClient = $optionsHttpClient;
    }

    /**
     * Verify by hash
     * @param type $workingDir
     * @return type
     */
    public function verifyPhotos($workingDir)
    {
        $directoryToProcess = realpath($workingDir);

        $flickr = new Flickr($this->_accessToken, $this->_optionsOAuth, $this->_optionsHttpClient);

        $searchRegEx = '/itscaro\:photohash\=/';
        $tags = $flickr->tagsGetListUser($this->_accessToken->getParam('user_nsid'), $searchRegEx);

//        $this->_logger->debug(sprintf('Found %d photos on Flickr', count($tags)));

        $photoHash = [];
        foreach ($tags as $_tag) {
            $photoHash[] = preg_replace($searchRegEx, '', $_tag);
        }

        $localPhotoHash = [];
        $finder = $this->_scan($directoryToProcess, 'file');

//        $this->_logger->debug(sprintf('Found %d photos locally', $finder->count()));

        $i = 0;
        foreach ($finder as $_file) {
            $i++;
            /* @var $_file SplFileInfo */
            $localPhotoHash[$_file->getRealPath()] = hash_file('md5', $_file->getRealPath());
            if ($i % 100 == 0) {
//                $this->_logger->debug('Hashing done for ' . $i);
            }
        }

        $diffFlickrvsLocal = array_diff($photoHash, $localPhotoHash);

        $diffLocalvsFlickr = array_diff($localPhotoHash, $photoHash);

        return array(
            'diffFlickrvsLocal' => $diffFlickrvsLocal,
            'diffLocalvsFlickr' => $diffLocalvsFlickr
        );
    }

    /**
     * Verify only the number of photos not by hash
     * @param type $directory
     * @return type
     */
    public function verifyPhotosForDirectory($directory)
    {

        $flickr = new Flickr($this->_accessToken, $this->_optionsOAuth, $this->_optionsHttpClient);

        $params = array(
            'user_id' => $this->_accessToken->getParam('user_nsid'),
            "machine_tags" => implode(',', array(
                'itscaro:directory_origin=' . $this->_sanitizeTag($directory)
            )),
            "machine_tag_mode" => "all",
            'per_page' => 500
        );
        $photos = $flickr->photoSearchAll($params);
        
        return $photos;
    }

    protected function _scan($dir, $type)
    {
        $dirRealPath = realpath($dir);

//        $this->_output->writeln("<info>Scanning {$dirRealPath}...</info>");

        $finder = new Finder();
        $finder->setAdapter('php')
                ->in($dir);

        if ($type == 'directory') {
            $finder->directories();
        } else if ($type == 'file') {
            $finder->name('/.*\.(' . implode('|', $this->_supportedExtensions) . ')$/i');
        }

        return $finder;
    }

}
