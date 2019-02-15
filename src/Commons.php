<?php
declare(strict_types = 1);

namespace App;

use CURLFile;
use Exception;
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

    public function __construct(string $oauthUrl, Client $oauthClient, Session $session)
    {
        $this->apiUrl = preg_replace('|index\.php.*|', 'api.php', $oauthUrl);
        $this->accessToken = $session->get('oauth.access_token');
        $this->oauthClient = $oauthClient;
        $loggedInUser = $session->get('logged_in_user');
        $this->currentUser = $loggedInUser ? $loggedInUser->username : false;
    }

    /**
     * @return mixed[]
     */
    public function getRecentUploads(): array
    {
        if (!$this->currentUser) {
            return [];
        }
        $result = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'query',
            'prop' => 'imageinfo',
            'iiprop' => 'url|timestamp',
            'iiurlwidth' => '150',
            'generator' => 'allimages',
            'gaiuser' => $this->currentUser,
            'gaisort' => 'timestamp',
            'gaidir' => 'descending',
            'gailimit' => '10',
            'gaimime' => 'image/png|image/jpeg|image/gif|image/tiff',
            'format' => 'json',
        ]);
        $resultData = \GuzzleHttp\json_decode($result, true);
        $out = [];
        foreach ($resultData['query']['pages'] as $page) {
            $page['fulltitle'] = $page['title'];
            $page['title'] = substr($page['fulltitle'], 5);
            $out[] = $page;
        }
        return $out;
    }

    /**
     * @param string $title
     * @return mixed[]
     */
    public function getInfo(string $title): array
    {
        if ($this->info) {
            return $this->info;
        }
        $result1 = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'query',
            'prop' => 'imageinfo',
            'titles' => str_replace(' ', '_', $title),
            'format' => 'json',
            'iiprop' => 'url',
            'iiurlwidth' => '700',
        ]);
        $imageInfoResult = \GuzzleHttp\json_decode($result1, true);
        if (!isset($imageInfoResult['query']['pages'])) {
            return false;
        }
        $imageInfo = array_shift($imageInfoResult['query']['pages']);

        $result2 = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'parse',
            'prop' => 'wikitext|text',
            'page' => str_replace(' ', '_', $title),
            'format' => 'json',
        ]);
        $parse = \GuzzleHttp\json_decode($result2, true);

        //dd($imageInfo, $parse);
        $this->info = [
            'fulltitle' => $imageInfo['title'],
            'title' => substr($imageInfo['title'], 5),
            'wikitext' => $parse['parse']['wikitext']['*'],
            'html' => $parse['parse']['text']['*'],
            'url' => $imageInfo['imageinfo'][0]['descriptionurl'],
            'img_src' => $imageInfo['imageinfo'][0]['thumburl'],
        ];
        return $this->info;
    }

    /**
     * @param $title
     * @param $text
     * @param $comment
     * @return mixed[]
     */
    public function savePage(string $title, string $text, string $comment): array
    {
        $tokenResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'query',
            'meta' => 'tokens',
            'format' => 'json',
        ]);
        $tokenResultData = \GuzzleHttp\json_decode($tokenResult, true);
        $token = $tokenResultData['query']['tokens']['csrftoken'];

        $editResult = $this->oauthClient->makeOAuthCall($this->accessToken, $this->apiUrl, true, [
            'action' => 'edit',
            'title' => $title,
            'format' => 'json',
            'text' => $text,
            'summary' => $comment,
            'token' => $token,
        ]);
        return \GuzzleHttp\json_decode($editResult, true);
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

    public function getFlickrId(string $title): int
    {
        $info = $this->getInfo($title);
        preg_match('|https://www.flickr.com/photos/[^/]+/([0-9]+)|m', $info['html'], $matches);
        if (isset($matches[1])) {
            return (int)$matches[1];
        }
        // @TODO support https://flic.kr/p/2dTyzrk URLs.
    }
}
