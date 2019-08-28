<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Flickr;
use Krinkle\Intuition\Intuition;
use Samwilson\PhpFlickr\PhpFlickr;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
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
    public function main(PhpFlickr $flickr, Session $session, Intuition $intuition): Response
    {
        return $this->render('flickr_dupes.html.twig', [
            'page_name' => 'flickr_dupes',
            'flickr_logged_in' => (bool)$this->flickrAccess($flickr, $session),
        ]);
    }

    /**
     * Get basic info about all photos.
     * @Route("/flickr/dupes/info", name="flickr_dupes_info")
     */
    public function info(PhpFlickr $flickr, Session $session): Response
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
    public function next(PhpFlickr $flickr, Session $session, string $pageNum): Response
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
            10,
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
            'page' => $pageNum,
            //'total' => (int)$photos['total'],
        ]);
    }

    /**
     * @param PhpFlickr $flickr
     * @param string[][] $photo
     * @return mixed[]
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
            // If we've got a checksum tag, query for others with the same one.
            $search = $flickr->photos()->search(['user_id' => 'me', 'machine_tags' => $tag]);
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
     *     "/flickr/dupes/{id1}/{id2}",
     *     name="flickr_dupes_compare",
     *     requirements={"id1"="\d+", "id2"="\d+"}
     *     )
     */
    public function compare(Flickr $flickr, Session $session, string $id1, string $id2): Response
    {
        $photos = [];
        foreach ([1 => $id1, 2 => $id2] as $id) {
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
     *     "/flickr/dupes/delete",
     *     name="flickr_dupes_delete",
     *     methods="POST"
     *     )
     */
    public function delete(Flickr $flickr): Response
    {
    }
}
