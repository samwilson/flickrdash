<?php
declare(strict_types = 1);

namespace App;

use CURLFile;
use Exception;
use Krinkle\Intuition\Intuition;
use MediaWiki\OAuthClient\Client;
use MediaWiki\OAuthClient\Token;
use Symfony\Component\HttpFoundation\Session\Session;

class Commons
{

    /** @var Token */
    protected $accessToken;

    /** @var Client */
    protected $oauthClient;

    /** @var string */
    protected $apiUrl;

    /** @var string */
    protected $currentUser;

    /** @var mixed[] */
    protected $info;

    /** @var string */
    protected $lang;

    public function __construct(string $wikiUrl, Client $oauthClient, Session $session, Intuition $intuition)
    {
        $this->apiUrl = $wikiUrl;
        $this->accessToken = $session->get('oauth.access_token');
        $this->oauthClient = $oauthClient;
        $loggedInUser = $session->get('logged_in_user');
        $this->currentUser = $loggedInUser ? $loggedInUser->username : false;
        $this->lang = $intuition->getLang();
    }

    /**
     * @return mixed[]
     */
    public function getRecentUploads(): array
    {
        if (!$this->currentUser) {
            return [];
        }
        $params = [
            'action' => 'query',
            'prop' => 'imageinfo',
            'iiprop' => 'url|timestamp',
            'iiurlwidth' => '150',
            'generator' => 'allimages',
            'gaiuser' => $this->currentUser,
            'gaisort' => 'timestamp',
            'gaidir' => 'descending',
            'gailimit' => '10',
            //'gaimime' => 'image/png|image/jpeg|image/gif|image/tiff',
            'format' => 'json',
        ];
        $result = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, $params);
        $resultData = \GuzzleHttp\json_decode($result, true);
        $out = [];
        if (isset($resultData['error']['info'])) {
            throw new Exception($resultData['error']['info']);
        }
        if (isset($resultData['query']['pages'])) {
            foreach ($resultData['query']['pages'] as $page) {
                $page['fulltitle'] = $page['title'];
                $page['title'] = substr($page['fulltitle'], 5);
                $out[] = $page;
            }
        }
        return $out;
    }

    /**
     * @param string $title
     * @return mixed[]|bool
     */
    public function getInfo(string $title)
    {
        if ($this->info) {
            return $this->info;
        }
        $result1 = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'query',
            'prop' => 'imageinfo|coordinates',
            'titles' => str_replace(' ', '_', $title),
            'format' => 'json',
            'iiprop' => 'url',
            'iiurlwidth' => '700',
        ]);
        $imageInfoResult = \GuzzleHttp\json_decode($result1, true);
        if (!isset($imageInfoResult['query']['pages'])) {
            return [];
        }
        $imageInfo = array_shift($imageInfoResult['query']['pages']);
        if (isset($imageInfo['missing'])) {
            return false;
        }

        // Get coordinates.
        $coords = ['lat' => '', 'lon' => ''];
        if (isset($imageInfo['coordinates'])) {
            foreach ($imageInfo['coordinates'] as $coord) {
                if (isset($coord['primary'])) {
                    $coords = ['lat' => $coord['lat'], 'lon' => $coord['lon']];
                }
            }
        }

        $result2 = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'parse',
            'prop' => 'wikitext|text',
            'page' => str_replace(' ', '_', $title),
            'format' => 'json',
        ]);
        $parse = \GuzzleHttp\json_decode($result2, true);

        // Get caption.
        $mediaId = 'M'.$imageInfo['pageid'];
        $wbGetEntitiesResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => $mediaId,
        ]);
        $wbGetEntities = \GuzzleHttp\json_decode($wbGetEntitiesResult, true);

        $this->info = [
            'pageid' => $imageInfo['pageid'],
            'fulltitle' => $imageInfo['title'],
            'title' => substr($imageInfo['title'], 5),
            'wikitext' => $parse['parse']['wikitext']['*'],
            'html' => $parse['parse']['text']['*'],
            'url' => $imageInfo['imageinfo'][0]['descriptionurl'],
            'img_src' => $imageInfo['imageinfo'][0]['thumburl'],
            'coordinates' => $coords,
            'caption' => $wbGetEntities['entities'][$mediaId]['labels'][$this->lang]['value'] ?? null,
        ];
        return $this->info;
    }

    /**
     * @param $title
     * @param $text
     * @param $comment
     * @return bool
     */
    public function savePage(string $title, string $text, string $comment): bool
    {
        $info = $this->getInfo($title);
        if ($text === $info['wikitext']) {
            // If nothing's changed, don't save.
            return false;
        }
        $tokenResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'query',
            'meta' => 'tokens',
            'format' => 'json',
        ]);
        $tokenResultData = \GuzzleHttp\json_decode($tokenResult, true);
        $token = $tokenResultData['query']['tokens']['csrftoken'];

        $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'edit',
            'title' => $title,
            'format' => 'json',
            'text' => $text,
            'summary' => $comment,
            'token' => $token,
        ]);
        return true;
    }

    /**
     * @param string $title Page title, with 'File'
     * @param string $caption
     * @return bool
     */
    public function setCaption(string $title, string $caption): bool
    {
        $info = $this->getInfo($title);
        if ($info['caption'] === $caption) {
            // No change.
            return false;
        }

        // Get token.
        $tokenResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'query',
            'meta' => 'tokens',
            'format' => 'json',
        ]);
        $tokenResultData = \GuzzleHttp\json_decode($tokenResult, true);
        $token = $tokenResultData['query']['tokens']['csrftoken'];

        // Save label.
        $mediaId = 'M'.$info['pageid'];
        $params = [
            'action' => 'wbsetlabel',
            'language' => $this->lang,
            'id' => $mediaId,
            'value' => $caption,
            'format' => 'json',
            'token' => $token,
        ];
        $wbSetLabelResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, $params);
        $wbSetLabelData = \GuzzleHttp\json_decode($wbSetLabelResult, true);
        return isset($wbSetLabelData['success']) && 1 === $wbSetLabelData['success'];
    }

    /**
     * @param int $flickrId
     * @param Flickr $flickr
     * @param string $title
     * @param string $text
     * @return mixed[]
     */
    public function upload(int $flickrId, Flickr $flickr, string $title, string $text): array
    {
        // Download from FLickr.
        $flickrInfo = $flickr->getInfo($flickrId);
        $tmpFlickrDownload = tempnam(sys_get_temp_dir(), 'flickr');
        copy($flickrInfo['original_url'], $tmpFlickrDownload);

        // 1. Get the CSRF token.
        $csrfTokenParams = [
            'format' => 'json',
            'action' => 'query',
            'meta' => 'tokens',
            'type' => 'csrf',
        ];
        $csrfTokenResponse = $this->oauthClient->makeOAuthCall(
            $this->accessToken,
            $this->apiUrl,
            true,
            $csrfTokenParams
        );
        $csrfTokenData = \GuzzleHttp\json_decode($csrfTokenResponse);
        if (!isset($csrfTokenData->query->tokens->csrftoken)) {
            throw new Exception("Unable to get CSRF token from: $csrfTokenResponse");
        }

        // 2. Upload the file.
        $uploadParams = [
            'format' => 'json',
            'action' => 'upload',
            'filename' => preg_replace('|\.jpeg$|', '.jpg', $title),
            'token' => $csrfTokenData->query->tokens->csrftoken,
            'comment' => $text,
            'filesize' => filesize($tmpFlickrDownload),
            'file' => new CURLFile($tmpFlickrDownload),
            // We have to ignore warnings so that we can overwrite the existing image.
            'ignorewarnings' => true,
        ];
        $uploadResponse = $this->oauthClient->makeOAuthCall(
            $this->accessToken,
            $this->apiUrl,
            true,
            $uploadParams
        );
        $uploadResponseData = \GuzzleHttp\json_decode($uploadResponse, true);
        if (!isset($uploadResponseData['upload']['result']) || 'Success' !== $uploadResponseData['upload']['result']) {
            throw new Exception('Upload failed. Response was: '.$uploadResponse);
        }

        return $uploadResponseData['upload'];
    }

    /**
     * @param string $title
     * @return int|bool
     */
    public function getFlickrId(string $title)
    {
        $info = $this->getInfo($title);
        if (!isset($info['html'])) {
            return false;
        }
        preg_match('|https://www.flickr.com/photos/[^/]+/([0-9]+)|m', $info['html'], $matches);
        if (isset($matches[1])) {
            return (int)$matches[1];
        }
        // @TODO support https://flic.kr/p/2dTyzrk URLs.
    }

    public static function getLicenses()
    {
        return [
            ['flickr_license_id' => 5, 'commons_template' => 'cc-by-sa-2.0'],
            ['flickr_license_id' => 4, 'commons_template' => 'cc-by-2.0'],
            ['flickr_license_id' => 9, 'commons_template' => 'cc-zero'],
            ['flickr_license_id' => 8, 'commons_template' => 'pd-usgov'],
        ];
    }

    public function getNextNonGeolocated()
    {
        $params = [
            'action' => 'query',
            'format' => 'json',
            'list' => 'usercontribs',
            'ucuser' => $this->currentUser,
            'ucnamespace' => 6,
            'ucprop' => 'title',
            // 'uclimit' = 500,
        ];
        $contribsData = null;
        do {
            if (isset($contribsData['continue']['uccontinue'])) {
                $params['uccontinue'] = $contribsData['continue']['uccontinue'];
            }
            $contribsResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, $params);
            $contribsData = \GuzzleHttp\json_decode($contribsResult, true);

            // Collate this batch of file titles.
            $allTitles = [];
            foreach ($contribsData['query']['usercontribs'] as $contrib) {
                // Title has the 'File:' prefix.
                $allTitles[] = $contrib['title'];
            }
            $titles = array_unique($allTitles);

            // Get coordinates of this batch of titles.
            $coordsParams = [
                'action' => 'query',
                'format' => 'json',
                'prop' => 'coordinates',
                'titles' => join('|', $titles),
                'coprop' => 'type|name|dim|country|region',
                'coprimary' => 'all',
            ];
            $coordsResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, $coordsParams);
            $coordsData = \GuzzleHttp\json_decode($coordsResult, true);

            // See if there is a file without coordinates.
            foreach ($coordsData['query']['pages'] as $coordInfo) {
                if (empty($coordInfo['coordinates'])) {
                    return $coordInfo['title'];
                }
            }

        } while (isset($contribsData['continue']['uccontinue']));

        // If all files have coordinates, return false.
        return false;
    }
}
