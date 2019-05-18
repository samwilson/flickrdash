<?php
declare(strict_types = 1);

namespace App;

use App\Controller\FlickrAuthController;
use DateTime;
use Samwilson\PhpFlickr\PhotosApi;
use Samwilson\PhpFlickr\PhpFlickr;
use Symfony\Component\HttpFoundation\Session\Session;

class Flickr
{

    /** @var PhpFlickr */
    protected $flickr;

    /** @var string */
    protected $sessionKey = 'phpflickr-oauth-access-token';

    /** @var mixed[] */
    protected $info;

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
     * @param bool $extended
     * @return mixed[]
     */
    public function getInfo(int $id, ?bool $extended = false): array
    {
        if ($this->info) {
            return $this->info;
        }
        $photoInfo = $this->flickr->photos()->getInfo($id);
        $photoInfo['shorturl'] = $this->flickr->urls()->getShortUrl($id);
        $photoInfo['img_src'] = $this->flickr->urls()->getImageUrl($photoInfo, PhotosApi::SIZE_MEDIUM_800);
        $photoInfo['original_url'] = $this->flickr->urls()->getImageUrl($photoInfo, PhotosApi::SIZE_ORIGINAL);
        if ($extended) {
            $photoInfo['dateuploaded_formatted'] = date('Y-m-d H:i:s', (int)$photoInfo['dateuploaded']);
            $sizes = $this->flickr->photos()->getSizes($id);
            foreach ($sizes['size'] as $size) {
                $photoInfo['sizes'][$size['label']] = $size;
            }
            $photoInfo['contexts'] = $this->flickr->photos_getAllContexts($id);
        }
        $photoInfo['commons_license_template'] = '';
        foreach (Commons::getLicenses() as $license) {
            if ((int)$license['flickr_license_id'] === (int)$photoInfo['license']) {
                $photoInfo['commons_license_template'] = $license['commons_template'];
            }
        }
        $photoInfo['perms'] = [];
        foreach (['public', 'friend', 'family'] as $audience) {
            if ($photoInfo['visibility']['is'.$audience]) {
                $photoInfo['perms'][] = $audience;
            }
        }
        $photoInfo['ismine'] = $this->flickr->test()->login()['id'] === $photoInfo['owner']['nsid'];
        $this->info = $photoInfo;
        return $this->info;
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
            'url_'.PhotosApi::SIZE_THUMBNAIL,
            20
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
        if (!empty($data['tags'])) {
            $this->flickr->photos()->setTags($photoId, $data['tags']);
        }
        /*
        $this->flickr->photos_setPerms(
            $photoId,
            isset($data['perms']['public']),
            isset($data['perms']['friend']),
            isset($data['perms']['family']),
            null,
            null
        );
        */
    }

    /**
     * @param int $photoId
     * @return string|bool The file, with 'File:' prepended.
     */
    public function getCommonsTitle(int $photoId)
    {
        $info = $this->getInfo($photoId);
        preg_match('|https://commons.wikimedia.org/wiki/([^"\'<>\|]+)|m', $info['description'], $matches);
        if (isset($matches[1])) {
            return $matches[1];
        }
        return false;
    }

    /**
     * @param string $searchTerm
     * @return string[][]
     */
    public function getTags(string $searchTerm): array
    {
        $tags = $this->flickr->tags_getRelated($searchTerm);
        $out = [];
        foreach ($tags['tag'] as $tag) {
            $out[] = $tag;
        }
        return $out;
    }

    /**
     * @return int|bool
     */
    public function getNextNonGeolocated()
    {
        $page = 1;
        do {
            $res = $this->flickr->people()->getPhotos(
                'me',
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                'geo',
                100,
                $page
            );
            foreach ($res['photo'] as $photo) {
                if (0 === $photo['latitude'] && 0 === $photo['longitude']) {
                    return (int)$photo['id'];
                }
            }
            $page++;
        } while ($res['page'] <= $res['total']);
        return false;
    }
}
