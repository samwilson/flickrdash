<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Commons;
use App\Flickr;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class GeoController extends ControllerBase
{

    /**
     * @Route("/commons/geotodo", name="commons_geotodo")
     */
    public function commonsTodo(Commons $commons): Response
    {
        $next = $commons->getNextNonGeolocated();
        return $this->redirectToRoute('editCommons', ['commonsTitle' => $next]);
    }

    /**
     * @Route("/flickr/geotodo", name="flickr_geotodo")
     */
    public function flickrTodo(Flickr $flickr): Response
    {
        $next = $flickr->getNextNonGeolocated();
        return $this->redirectToRoute('editFlickr', ['flickrId' => $next]);
    }
}
