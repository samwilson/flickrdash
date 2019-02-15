<?php
declare(strict_types = 1);

namespace App\Controller;

use OAuth\Common\Storage\Session;
use Samwilson\PhpFlickr\PhpFlickr;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session as SymfonySession;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FlickrAuthController extends AbstractController
{

    const SESSION_KEY = 'flickr_access_token';

    /**
     * @Route("/flickr/login", name="flickr_login")
     */
    public function login(PhpFlickr $flickr)
    {
        $storage = new Session();
        $flickr->setOauthStorage($storage);
        $callbackUrl = $this->generateUrl('flickr_login_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $authUrl = $flickr->getAuthUrl('delete', $callbackUrl);
        return $this->redirect($authUrl->getAbsoluteUri());
    }

    /**
     * @Route("/flickr/oauth_callback", name="flickr_login_callback")
     */
    public function callback(Request $request, PhpFlickr $flickr, SymfonySession $session)
    {
        $storage = new Session();
        $flickr->setOauthStorage($storage);
        $verifier = $request->query->get('oauth_verifier');
        $token = $request->query->get('oauth_token');
        $accessToken = $flickr->retrieveAccessToken($verifier, $token);
        $session->set(static::SESSION_KEY, $accessToken);
        return $this->redirectToRoute('home');
    }

    /**
     * @Route("/flickr/logout", name="flickr_logout")
     */
    public function logout(SymfonySession $session)
    {
        $session->remove(static::SESSION_KEY);
        return $this->redirectToRoute('home');
    }
}
