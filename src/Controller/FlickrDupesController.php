<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Flickr;
use Krinkle\Intuition\Intuition;
use OOUI\ButtonInputWidget;
use OOUI\FieldLayout;
use OOUI\TextInputWidget;
use Samwilson\PhpFlickr\PhpFlickr;
use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

class FlickrDupesController extends ControllerBase
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
    public function main(Request $request, PhpFlickr $flickr, Session $session, Intuition $intuition): Response
    {
        $paramsForRedirect = [];
        if ($request->get('id1')) {
            $paramsForRedirect['id1'] = $request->get('id1');
        }
        if ($request->get('id2')) {
            $paramsForRedirect['id2'] = $request->get('id2');
        }
        if (2 === count($paramsForRedirect)) {
            return $this->redirectToRoute('flickr_dupes_compare', $paramsForRedirect);
        }

        $photo1Field = new FieldLayout(
            new TextInputWidget(['name' => 'id1']),
            ['label' => $this->msg('flickr-dupes-photo1')]
        );
        $photo2Field = new FieldLayout(
            new TextInputWidget(['name' => 'id2']),
            ['label' => $this->msg('flickr-dupes-photo2')]
        );
        return $this->render('flickr_dupes.html.twig', [
            'page_name' => 'flickr_dupes',
            'flickr_logged_in' => (bool)$this->flickrAccess($flickr, $session),
            'photo1' => $photo1Field,
            'photo2' => $photo2Field,
            'dupeSearchButton' => new ButtonInputWidget([
                'label' => $this->msg('flickr-dupes-compare-button'),
                'type' => 'submit',
            ]),
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
            $photos[$id] = $flickr->getInfo((int)$id, true);
        }
        return $this->render('flickr_compare.html.twig', [
            'flickr_logged_in' => $session->has(FlickrAuthController::SESSION_KEY),
            'photos' => $photos,
        ]);
    }

    /**
     * Delete the given Flickr photo.
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
