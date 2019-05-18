<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Commons;
use App\Flickr;
use Mediawiki\Api\FluentRequest;
use Mediawiki\Api\MediawikiApi;
use OOUI\ButtonInputWidget;
use OOUI\ButtonWidget;
use OOUI\CheckboxInputWidget;
use OOUI\CheckboxMultiselectInputWidget;
use OOUI\DropdownInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\HorizontalLayout;
use OOUI\MultilineTextInputWidget;
use OOUI\TextInputWidget;
use Samwilson\PhpFlickr\FlickrException;
use Samwilson\PhpFlickr\Util;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends ControllerBase
{

    /**
     * @Route("/", name="home")
     */
    public function home(Session $session, Commons $commons, Flickr $flickr)
    {
        $flickrLoggedIn = false !== $flickr->getUserId();
        $recentFlickrFiles = [];
        if ($flickrLoggedIn) {
            $recentFlickrFiles = $flickr->getRecentUploads();
        }
        $commonsLoggedIn = $session->has('logged_in_user');
        $recentCommonsFiles = [];
        if ($commonsLoggedIn) {
            $recentCommonsFiles = $commons->getRecentUploads();
        }
        return $this->render('home.html.twig', [
            'commons_logged_in' => $commonsLoggedIn,
            'flickr_logged_in' => $flickrLoggedIn,
            'recent_commons_files' => $recentCommonsFiles,
            'recent_flickr_files' => $recentFlickrFiles,
            'commons_login_button' => new ButtonWidget([
                'label' => $this->msg('login'),
                'href' => $this->generateUrl('toolforge_login'),
            ]),
            'flickr_login_button' => new ButtonWidget([
                'label' => $this->msg('flickr-login'),
                'href' => $this->generateUrl('flickr_login'),
            ]),
        ]);
    }

    /**
     * @Route("edit/{commonsTitle}", name="editCommons", requirements={"commonsTitle"="File:.*"}, methods={"GET"})
     * @Route("edit/{flickrId}", name="editFlickr", requirements={"flickrId"="\d+"}, methods={"GET"})
     * @Route("edit/{flickrId}/{commonsTitle}", name="editBoth", requirements={"commonsTitle"="File:.*", "flickrId"="\d+"}, methods={"GET"})
     */
    public function edit(Commons $commons, Session $session, Flickr $flickr, $commonsTitle = '', $flickrId = '')
    {
        $alerts = [];
        if (!empty($commonsTitle) && empty($flickrId)) {
            $extractedFlickrId = $commons->getFlickrId($commonsTitle);
            if ($extractedFlickrId) {
                try {
                    $flickr->getInfo($extractedFlickrId);
                    return $this->redirectToRoute(
                        'editBoth',
                        ['commonsTitle' => $commonsTitle, 'flickrId' => $extractedFlickrId]
                    );
                } catch (FlickrException $exception) {
                    $alerts[] = [
                        'type'=> 'warning',
                        'message' => $this->msg('flickr-extracted-id-not-found', [$extractedFlickrId]),
                    ];
                }
            }
        }
        if (empty($commonsTitle) && !empty($flickrId)) {
            $commonsTitle = $flickr->getCommonsTitle((int)$flickrId);
            if ($commonsTitle && false !== $commons->getInfo($commonsTitle)) {
                return $this->redirectToRoute(
                    'editBoth',
                    ['commonsTitle' => $commonsTitle, 'flickrId' => $flickrId]
                );
            }
        }

        $flickrFile = false;
        if ($flickrId) {
            $flickrFile = $flickr->getInfo((int)$flickrId);
        }

        $commonsFile = false;
        if ($commonsTitle) {
            $commonsFile = $commons->getInfo($commonsTitle);
            if (!$commonsFile) {
                $this->addFlash('notice', 'Unable to retrieve details of '.$commonsTitle);
                return $this->redirectToRoute('home');
            }
        }

        // Commons fields.
        $commonsLoggedIn = $session->has('logged_in_user');
        $commonsFieldset = new FieldsetLayout();
        if (!$commonsFile) {
            $commonsUploadWidget = new CheckboxInputWidget([
                'id' => 'commons-upload-widget',
                'name' => 'commons[upload]',
                'infusable' => true,
                // Disable if the user is not logged in.
                'disabled' => !$commonsLoggedIn,
            ]);
            $commonsUploadField = new FieldLayout($commonsUploadWidget, [
                'label' => $this->msg('commons-upload'),
                'help' => $commonsLoggedIn ? '' : $this->msg('commons-upload-please-log-in'),
                'helpInline' => true,
            ]);
            $commonsTitleWidget = new TextInputWidget([
                'id' => 'commons-title-widget',
                'value' => isset($flickrFile['title']) ? $flickrFile['title'].'.'.$flickrFile['originalformat'] : '',
                'name' => 'commons[title]',
                'infusable' => true,
            ]);
            $commonsTitleField = new FieldLayout(
                $commonsTitleWidget,
                ['label' => $this->msg('commons-title'), 'align' => 'top']
            );
            $commonsFieldset->addItems([$commonsUploadField, $commonsTitleField]);
        }
        $commonsCaptionWidget = new TextInputWidget([
            'id' => 'commons-caption-widget',
            'name' => 'commons[caption]',
            'infusable' => true,
            'value' => $commonsFile['caption'],
        ]);
        $commonsCaptionField = new FieldLayout(
            $commonsCaptionWidget,
            ['label' => $this->msg('commons-caption', [$this->intuition->getLangName()]), 'align' => 'top']
        );
        $pageText = $commonsFile['wikitext'] ?? $this->renderView('commons.wikitext.twig', [
            'flickr_file' => $flickrFile,
            'lang' => $this->intuition->getLang(),
        ]);
        $commonsPageTextWidget =new MultilineTextInputWidget([
            'id' => 'commons-page-text-widget',
            'value' => $pageText,
            'name' => 'commons[page_text]',
            'rows' => 18,
            'infusable' => true,
        ]);
        $commonsPageTextField = new FieldLayout(
            $commonsPageTextWidget,
            ['label' => $this->msg('commons-page-text'), 'align' => 'top']
        );
        $commonsFieldset->addItems([$commonsCaptionField, $commonsPageTextField]);
        if ($commonsFile) {
            $commonsCommentWidget = new TextInputWidget([
                'id' => 'commons-comment-widget',
                'name' => 'commons[comment]',
                'infusable' => true,
            ]);
            $commonsCommentField = new FieldLayout(
                $commonsCommentWidget,
                ['label' => $this->msg('commons-change-comment'), 'align' => 'top']
            );
            $commonsFieldset->addItems([$commonsPageTextField, $commonsCommentField]);
        }

        // Flickr fields.
        $flickrTitleWidget = new TextInputWidget([
            'value' => $flickrFile['title'] ?? '',
            'name' => 'flickr[title]',
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrTitleField = new FieldLayout(
            $flickrTitleWidget,
            ['label' => $this->msg('flickr-title'), 'align' => 'top']
        );
        $flickrDescriptionWidget =new MultilineTextInputWidget([
            'id' => 'flickr-description-widget',
            'value' => $flickrFile['description'],
            'name' => 'flickr[description]',
            'rows' => 5,
            'infusable' => true,
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrDescriptionField = new FieldLayout(
            $flickrDescriptionWidget,
            ['label' => $this->msg('flickr-description'), 'align' => 'top']
        );
        $flickrDateTakenWidget = new TextInputWidget([
            'value' => $flickrFile['dates']['taken'] ?? '',
            'name' => 'flickr[datetaken]',
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrDateTakenField = new FieldLayout(
            $flickrDateTakenWidget,
            ['label' => $this->msg('flickr-date-taken'), 'align' => 'top']
        );
        $flickrDateTakenGranularityWidget = new DropdownInputWidget([
            'options' => [
                ['data' => Util::DATE_GRANULARITY_EXACT, 'label' => $this->msg('flickr-granularity-exact')],
                ['data' => Util::DATE_GRANULARITY_MONTH, 'label' => $this->msg('flickr-granularity-month')],
                ['data' => Util::DATE_GRANULARITY_YEAR, 'label' => $this->msg('flickr-granularity-year')],
                ['data' => Util::DATE_GRANULARITY_CIRCA, 'label' => $this->msg('flickr-granularity-circa')],
            ],
            'value' => $flickrFile['dates']['takengranularity'] ?? '',
            'name' => 'flickr[datetakengranularity]',
            'infusable' => true,
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrDateTakenGranularityField = new FieldLayout(
            $flickrDateTakenGranularityWidget,
            ['label' => $this->msg('flickr-date-taken-granularity'), 'align' => 'top']
        );
        $licenses = $commons->getLicenses();
        $licenseOptions = [['data' => '']];
        foreach ($licenses as $license) {
            $licenseOptions[] = ['data' => $license['flickr_license_id'], 'label' => $this->msg('license-'.$license['commons_template'])];
        }
        $flickrLicenseWidget = new DropdownInputWidget([
            'options' => $licenseOptions,
            'value' => $flickrFile['license'] ?? '',
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrLicenseField = new FieldLayout(
            $flickrLicenseWidget,
            ['label' => $this->msg('flickr-license'), 'align' => 'top']
        );
        $flickrLocationLatitudeWidget = new TextInputWidget([
            'value' => $flickrFile['location']['latitude'] ?? '',
            'name' => 'flickr[latitude]',
            'infusable' => true,
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrLocationLatitudeField = new FieldLayout(
            $flickrLocationLatitudeWidget,
            ['label' => $this->msg('flickr-location-latitude'), 'align' => 'top']
        );
        $flickrLocationLongitudeWidget = new TextInputWidget([
            'value' => $flickrFile['location']['longitude'] ?? '',
            'name' => 'flickr[longitude]',
            'infusable' => true,
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrLocationLongitudeField = new FieldLayout(
            $flickrLocationLongitudeWidget,
            ['label' => $this->msg('flickr-location-longitude'), 'align' => 'top']
        );
        $flickrLocationAccuracyWidget = new DropdownInputWidget([
            'options' => [
                ['data' => 16, 'label' => '16: '.$this->msg('flickr-location-accuracy-street')],
                ['data' => 15],
                ['data' => 14],
                ['data' => 13],
                ['data' => 12],
                ['data' => 11, 'label' => '11: '.$this->msg('flickr-location-accuracy-city')],
                ['data' => 10],
                ['data' => 9],
                ['data' => 8],
                ['data' => 7],
                ['data' => 6, 'label' => '6: '.$this->msg('flickr-location-accuracy-region')],
                ['data' => 5],
                ['data' => 4],
                ['data' => 3, 'label' => '3: '.$this->msg('flickr-location-accuracy-country')],
                ['data' => 2],
                ['data' => 1, 'label' => '1: '.$this->msg('flickr-location-accuracy-world')],
            ],
            'value' => $flickrFile['location']['accuracy'] ?? '1',
            'name' => 'flickr[accuracy]',
            'infusable' => true,
            'disabled' => !$flickrFile['ismine'],
        ]);
        $flickrLocationAccuracyField = new FieldLayout(
            $flickrLocationAccuracyWidget,
            ['label' => $this->msg('flickr-location-accuracy'), 'align' => 'top']
        );
        $flickrPermsWidget = new CheckboxMultiselectInputWidget([
            'options' => [
                ['data' => 'public', 'label' => $this->msg('flickr-perms-public')],
                ['data' => 'friend', 'label' => $this->msg('flickr-perms-friend')],
                ['data' => 'family', 'label' => $this->msg('flickr-perms-family')],
            ],
            'value' => $flickrFile['perms'] ?? '',
            'name' => 'flickr[perms][]',
            'infusable' => true,
            'disabled' => true,
        ]);
        $flickrPermsField = new FieldLayout(
            $flickrPermsWidget,
            ['label' => $this->msg('flickr-visibility'), 'align' => 'top']
        );
        $flickrTagsWidget = new TextInputWidget([
            'name' => 'flickr[tags]',
            'disabled' => !$flickrFile['ismine'] || 3 !== $flickrFile['permissions']['permaddmeta'],
        ]);
        $flickrTagsWidget->setData($flickrFile['tags']['tag']);
        $flickrTagsField = new FieldLayout(
            $flickrTagsWidget,
            [
                'label' => $this->msg('flickr-tags'),
                'align' => 'top',
                'infusable' => true,
            ]
        );
        $flickrFieldset = new FieldsetLayout(['items' => [
            $flickrTitleField,
            $flickrDescriptionField,
            new HorizontalLayout([
                'items' => [$flickrDateTakenField, $flickrDateTakenGranularityField, $flickrLicenseField],
            ]),
            new HorizontalLayout(['items' => [
                $flickrLocationLatitudeField,
                $flickrLocationLongitudeField,
                $flickrLocationAccuracyField,
            ]]),
            $flickrTagsField,
            $flickrPermsField,
        ]]);

        // Other fields.
        $saveButton = new ButtonInputWidget([
            'label' => $this->msg('save'),
            'type' => 'submit',
        ]);
        return $this->render('edit.html.twig', [
            'alerts' => $alerts,
            'flickr_logged_in' => $session->has(FlickrAuthController::SESSION_KEY),
            'commons_file' => $commonsFile,
            'flickr_file' => $flickrFile,
            'commons_fieldset' => $commonsFieldset,
            'flickr_fieldset' => $flickrFieldset,
            'save_button' => $saveButton,
        ]);
    }

    /**
     * @Route("edit/{commonsTitle}", name="saveCommons", requirements={"commonsTitle"="File:.*"}, methods={"POST"})
     * @Route("edit/{flickrId}", name="saveFlickr", requirements={"flickrId"="\d+"}, methods={"POST"})
     * @Route("edit/{flickrId}/{commonsTitle}", name="saveBoth", requirements={"commonsTitle"="File:.*", "flickrId"="\d+"}, methods={"POST"})
     */
    public function save(
        Request $request,
        Commons $commons,
        Flickr $flickr,
        Session $session,
        $commonsTitle = '',
        $flickrId = ''
    ) {
        $requestParams = $request->request->all();

        $commonsLoggedIn = $session->has('logged_in_user');

        if ($commonsLoggedIn && isset($requestParams['commons']['upload']) && $flickrId) {
            // Save new Commons photo.
            $uploaded = $commons->upload(
                (int)$flickrId,
                $flickr,
                $requestParams['commons']['title'],
                $requestParams['commons']['page_text']
            );
            $commonsTitle = 'File:'.$uploaded['filename'];
            // Add link from Flickr to Commons.
            if (isset($requestParams['flickr']['description'])) {
                $requestParams['flickr']['description'] .= "\n\n"
                    ."<a href='https://commons.wikimedia.org/wiki/File:".urlencode($uploaded['filename'])."' rel='noreferrer nofollow'>"
                    .$uploaded['filename']
                    ."</a>";
            }
            // Tell the user.
            $this->addFlash('notice', $this->msg('commons-photo-uploaded'));
        } elseif ($commonsLoggedIn && !empty($requestParams['commons']['comment'])) {
            // Save existing Commons photo.
            $commons->savePage(
                $commonsTitle,
                $requestParams['commons']['page_text'],
                $requestParams['commons']['comment']
            );
            $this->addFlash('notice', $this->msg('commons-data-saved'));
        }
        // Save Commons caption.
        if ($commonsLoggedIn && !empty($requestParams['commons']['caption'])) {
            $commons->setCaption($commonsTitle, $requestParams['commons']['caption']);
        }
        // Save Flickr info.
        if (!empty($flickrId) && isset($requestParams['flickr']['title'])) {
            $flickr->save((int)$flickrId, $requestParams['flickr']);
            $this->addFlash('notice', $this->msg('flickr-data-saved'));
        }

        // If we have both, redirect to both.
        if ($commonsTitle && $flickrId) {
            return $this->redirectToRoute('editBoth', [
                'commonsTitle' => $commonsTitle,
                'flickrId' => $flickrId,
            ]);
        }
        // If only Flickr, redirect there.
        if (!$commonsTitle && $flickrId) {
            return $this->redirectToRoute('editFlickr', ['flickrId' => $flickrId]);
        }
        // 3. And if only Commons, redirect there.
        return $this->redirectToRoute('editCommons', ['commonsTitle' => $commonsTitle]);
    }

    /**
     * @Route("/tags", name="tags")
     */
    public function tagSearch(Request $request, Flickr $flickr)
    {
        $resultCount = 10;
        $searchTerm = $request->get('q');
        $results = [];
        if ($searchTerm) {
            // Get Wikidata.
            $api = MediawikiApi::newFromApiEndpoint('https://www.wikidata.org/w/api.php');
            $req = FluentRequest::factory()
                ->setAction('wbsearchentities')
                ->addParams([
                    'search' => $searchTerm,
                    'type' => 'item',
                    'limit' => $resultCount,
                    'language' => 'en',
                ]);
            $response = $api->getRequest($req);
            foreach ($response['search'] as $info) {
                $result = ['itemid' => $info['id'], 'data' => $info['label']];
                if (isset($info['description'])) {
                    $result['description'] = $info['description'];
                }
                if (isset($info['aliases'])) {
                    $result['aliases'] = $info['aliases'];
                }
                $results[] = $result;
            }
            // Get Flickr.
            if (count($results) < $resultCount) {
                foreach (array_slice($flickr->getTags($searchTerm), 0, $resultCount) as $tag) {
                    $results[] = ['label' => $tag];
                }
            }
        }
        return new JsonResponse(array_slice($results, 0, $resultCount));
    }
}
