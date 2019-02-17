<?php
declare(strict_types = 1);

namespace App;

use App\Controller\FlickrAuthController;
use DateTime;
use Samwilson\PhpFlickr\FlickrException;
use Samwilson\PhpFlickr\PhotosApi;
use Samwilson\PhpFlickr\PhpFlickr;
use Samwilson\PhpFlickr\Util;
use Symfony\Component\HttpFoundation\Session\Session;

class Flickr
{

    /** @var PhpFlickr */
    protected $flickr;

    /** @var string */
    protected $sessionKey = 'phpflickr-oauth-access-token';

    public function __construct(Session $session, PhpFlickr $phpFlickr)
    {
        $accessToken = $session->get(FlickrAuthController::SESSION_KEY);
        if ($accessToken) {
            $phpFlickr->getOauthTokenStorage()->storeAccessToken('Flickr', $accessToken);
        }
        $this->flickr = $phpFlickr;
    }

    /**
     * @param int $id
     * @return mixed[]
     */
    public function getInfo(int $id, $extended = false): array
    {
        $photoInfo = $this->flickr->photos()->getInfo($id);
        $photoInfo['shorturl'] = 'https://flic.kr/p/'.Util::base58encode($id);
        $photoInfo['img_src'] = $this->flickr->buildPhotoURL($photoInfo, PhotosApi::SIZE_MEDIUM_800);
        $photoInfo['original_url'] = $this->flickr->buildPhotoURL($photoInfo, PhotosApi::SIZE_ORIGINAL);
        if ($extended) {
            $photoInfo['dateuploaded_formatted'] = date('Y-m-d H:i:s', (int)$photoInfo['dateuploaded']);
            $sizes = $this->flickr->photos()->getSizes($id);
            foreach ($sizes['size'] as $size) {
                $photoInfo['sizes'][$size['label']] = $size;
            }
            $photoInfo['contexts'] = $this->flickr->photos_getAllContexts($id);
        }
        return $photoInfo;
    }

    /**
     * @return mixed[]
     */
    public function getRecentUploads(): array
    {
        if (!$this->flickr->test()->login()) {
            return [];
        }
        $photos = $this->flickr->people()->getPhotos(
            'me',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'url_'.PhotosApi::SIZE_THUMBNAIL
        );
        return $photos['photo'];
    }

    /**
     * @param int $photoId
     * @param string[] $data
     */
    public function save(int $photoId, array $data): void
    {
        $this->flickr->photos()->setMeta($photoId, $data['title'], $data['description']);
        $this->flickr->photos()->setDates($photoId, new DateTime($data['datetaken']), $data['datetakengranularity']);
        if (!empty($data['latitude'])) {
            $this->flickr->photos_geo_setLocation($photoId, $data['latitude'], $data['longitude'], $data['accuracy']);
        }
    }
}
