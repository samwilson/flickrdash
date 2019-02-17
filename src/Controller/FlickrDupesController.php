<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Flickr;
use Krinkle\Intuition\Intuition;
use Samwilson\PhpFlickr\PhpFlickr;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

class FlickrDupesController extends AbstractController
{

    /**
     * @return PhpFlickr|bool
     */
    protected function flickrAccess(PhpFlickr $flickr, Session $session)
    {
        $accessToken = $session->get(FlickrAuthController::SESSION_KEY);
        if (!$accessToken) {
            throw new AccessDeniedException('No ya');
        }
        $flickr->getOauthTokenStorage()->storeAccessToken('Flickr', $accessToken);
        return $flickr;
    }

    /**
     * @Route("/flickr/dupes", name="flickr_dupes")
     */
    public function main(PhpFlickr $flickr, Session $session, Intuition $intuition)
    {
//        $searchButton = new ButtonWidget([
//            'label' => $intuition->msg('find-duplicates'),
//            'infusable' => true,
//            'id' => 'flickr-dupes-search-button',
//        ]);
//        $progressBar = new ProgressBarWidget(['infusable' => true]);
        return $this->render('flickr_dupes.html.twig', [
            'page_name' => 'flickr_dupes',
            'flickr_logged_in' => (bool)$this->flickrAccess($flickr, $session),
//            'progress_bar' => $progressBar,
//            'search_button' => $searchButton,
        ]);
    }

    /**
     * Get basic info about all photos.
     * @Route("/flickr/dupes/info", name="flickr_dupes_info")
     */
    public function info(PhpFlickr $flickr, Session $session)
    {
        $this->flickrAccess($flickr, $session);
        $info = $flickr->people()->getPhotos();
        return new JsonResponse([
            'pages' => (int)$info['pages'],
            'total' => (int)$info['total'],
        ]);
    }

    /**
     * @Route("/flickr/dupes/info/{pageNum}", name="flickr_dupes_page")
     */
    public function next(PhpFlickr $flickr, Session $session, $pageNum)
    {
        $this->flickrAccess($flickr, $session);
        $photos = $flickr->people()->getPhotos(
            'me',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'tags',
            100,
            $pageNum
        );
        if (0 === $photos['pages']) {
            // @TODO Handle no photos.
        }
        foreach ($photos['photo'] as $photo) {
            $dupes = $this->processPhoto($flickr, $photo);
            if ($dupes) {
                [$photo1, $photo2] = $dupes;
                return new JsonResponse([
                    'url' => true, // @TODO fix this.
                    'photo1' => $photo1,
                    'photo2' => $photo2,
                ]);
            }
        }
        return new JsonResponse([
            'pages' => (int)$photos['pages'],
            //'total' => (int)$photos['total'],
        ]);
    }

    /**
     * @param PhpFlickr $flickr
     * @param string[][] $photo
     * @return array Two photo-info arrays (keyed 0 and 1).
     */
    protected function processPhoto(PhpFlickr $flickr, array $photo): array
    {
        $tags = explode(' ', $photo['tags']);
        // Go through the plain tags and detect the ones we're interested by string comparison rather than by
        // querying the actual photo metadata, to save ourselves an extra request.
        foreach ($tags as $tag) {
            if ('checksum:' !== substr($tag, 0, strlen('checksum:'))) {
                continue;
            }
            // If we've got a checksum tag, query for others wit hthe same one.
            $tagParts = explode('=', $tag);
            $tagSearchString = $tagParts[0].'="'.$tagParts[1].'"';
            $search = $flickr->photos()->search(['machine_tags' => $tag]);
            dd($photo, $tag, $tagParts, $tagSearchString, $search);
            // https://www.flickr.com/photos/tags/checksum:sha1=31ae163e2fb5cd0798d4edc8f9259132f956eb25 works
            // https://www.flickr.com/photos/tags/checksum:sha1=31ae163e2fb5cd0798d4edc8f9259132f956eb25
            if ((int)$search['total'] < 2) {
                continue;
            }
            $prev = null;
            foreach ($search['photo'] as $searchResult) {
                $photoInfo = $flickr->photos()->getInfo($searchResult['id']);
                if (!$prev) {
                    $prev = $photoInfo;
                } else {
                    return [$prev, $photoInfo];
                }
            }
        }
        return [];
    }

    /**
     * Compare two FLickr photos and provide the means of deleting one.
     * @Route(
     *     "/flickrdupes/{id1}/{id2}",
     *     name="flickr_dupes_compare",
     *     requirements={"id1"="\d+", "id2"="\d+"}
     *     )
     */
    public function compare(Flickr $flickr, Session $session, $id1, $id2)
    {
        $photos = [];
        foreach ([1 => $id1, 2 => $id2] as $idx => $id) {
            $photos[] = $flickr->getInfo((int)$id, true);
        }
        return $this->render('flickr_compare.html.twig', [
            'flickr_logged_in' => $session->has(FlickrAuthController::SESSION_KEY),
            'photos' => $photos,
        ]);
    }

    /**
     * Compare two FLickr photos and provide the means of deleting one.
     * @Route(
     *     "/flickr_dupe_delete",
     *     name="flickr_dupe_delete",
     *     methods="POST"
     *     )
     */
    public function delete(Flickr $flickr)
    {

    }

}
