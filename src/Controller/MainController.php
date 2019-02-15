<?php
declare(strict_types = 1);

namespace App\Controller;

use App\Commons;
use App\Flickr;
use Krinkle\Intuition\Intuition;
use OOUI\ButtonInputWidget;
use OOUI\CheckboxInputWidget;
use OOUI\DropdownInputWidget;
use OOUI\FieldLayout;
use OOUI\FieldsetLayout;
use OOUI\HorizontalLayout;
use OOUI\MultilineTextInputWidget;
use OOUI\TextInputWidget;
use Samwilson\PhpFlickr\Util;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractController
{

    /** @var Intuition */
    protected $intuition;

    public function __construct(Intuition $intuition)
    {
        $this->intuition = $intuition;
    }

    protected function msg($message, $vars = [])
    {
        return $this->intuition->msg($message, ['variables' => $vars]);
    }

    /**
     * @Route("/", name="home")
     */
    public function home(Session $session, Commons $commons, Flickr $flickr)
    {
        $flickrLoggedIn = $session->has(FlickrAuthController::SESSION_KEY);
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
        ]);
    }

    /**
     * @Route("edit/{commonsTitle}", name="editCommons", requirements={"commonsTitle"="File:.*"}, methods={"GET"})
     * @Route("edit/{flickrId}", name="editFlickr", requirements={"flickrId"="\d+"}, methods={"GET"})
     * @Route("edit/{flickrId}/{commonsTitle}", name="editBoth", requirements={"commonsTitle"="File:.*", "flickrId"="\d+"}, methods={"GET"})
     */
    public function edit(Commons $commons, Session $session, Flickr $flickr, $commonsTitle = '', $flickrId = '')
    {
        if (!empty($commonsTitle) && empty($flickrId)) {
            $extractedFlickrId = $commons->getFlickrId($commonsTitle);
            if ($extractedFlickrId) {
                return $this->redirectToRoute(
                    'editBoth',
                    ['commonsTitle' => $commonsTitle, 'flickrId' => $extractedFlickrId]
                );
            }
        }

        $flickrFile = false;
        if ($flickrId) {
            $flickrFile = $flickr->getInfo($flickrId);
        }

        $commonsFile = $commons->getInfo($commonsTitle);

        // Commons fields.
        $commonsFieldset = new FieldsetLayout();
        if (!$commonsFile) {
            $commonsUploadWidget = new CheckboxInputWidget([
                'id' => 'commons-upload-widget',
                'name' => 'commons[upload]',
                'infusable' => true,
            ]);
            $commonsUploadField = new FieldLayout($commonsUploadWidget, [
                'label' => $this->msg('commons-upload'),
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
        ]);
        $commonsCaptionField = new FieldLayout(
            $commonsCaptionWidget,
            ['label' => $this->msg('commons-caption', [$this->intuition->getLangName()]), 'align' => 'top']
        );
        $commonsPageTextWidget =new MultilineTextInputWidget([
            'id' => 'commons-page-text-widget',
            'value' => $commonsFile['wikitext'] ?? $this->renderView('commons.wikitext.twig', ['flickr_file' => $flickrFile]),
            'name' => 'commons[page_text]',
            'rows' => 12,
            'infusable' => true,
        ]);
        $commonsPageTextField = new FieldLayout(
            $commonsPageTextWidget,
            ['label' => $this->msg('commons-page-text'), 'align' => 'top']
        );
        $commonsFieldset->addItems([$commonsCaptionField, $commonsPageTextField]);
        if ($commonsFile) {
            $commonsCommentWidget = new TextInputWidget([
                'name' => 'commons[comment]',
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
        ]);
        $flickrDescriptionField = new FieldLayout(
            $flickrDescriptionWidget,
            ['label' => $this->msg('flickr-description'), 'align' => 'top']
        );
        $flickrDateTakenWidget = new TextInputWidget([
            'value' => $flickrFile['dates']['taken'] ?? '',
            'name' => 'flickr[datetaken]',
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
        ]);
        $flickrDateTakenGranularityField = new FieldLayout(
            $flickrDateTakenGranularityWidget,
            ['label' => $this->msg('flickr-date-taken-granularity'), 'align' => 'top']
        );
        $flickrLocationLatitudeWidget = new TextInputWidget([
            'value' => $flickrFile['location']['latitude'] ?? '',
            'name' => 'flickr[latitude]',
            'infusable' => true,
        ]);
        $flickrLocationLatitudeField = new FieldLayout(
            $flickrLocationLatitudeWidget,
            ['label' => $this->msg('flickr-location-latitude'), 'align' => 'top']
        );
        $flickrLocationLongitudeWidget = new TextInputWidget([
            'value' => $flickrFile['location']['longitude'] ?? '',
            'name' => 'flickr[longitude]',
            'infusable' => true,
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
            'value' => $flickrFile['location']['accuracy'] ?? '',
            'name' => 'flickr[accuracy]',
            'infusable' => true,
        ]);
        $flickrLocationAccuracyField = new FieldLayout(
            $flickrLocationAccuracyWidget,
            ['label' => $this->msg('flickr-location-accuracy'), 'align' => 'top']
        );
        $flickrFieldset = new FieldsetLayout(['items' => [
            $flickrTitleField,
            $flickrDescriptionField,
            new HorizontalLayout(['items' => [$flickrDateTakenField, $flickrDateTakenGranularityField]]),
            new HorizontalLayout(['items' => [
                $flickrLocationLatitudeField,
                $flickrLocationLongitudeField,
                $flickrLocationAccuracyField,
            ]]),
        ]]);

        // Other fields.
        $saveButton = new ButtonInputWidget([
            'label' => $this->msg('save'),
            'type' => 'submit',
        ]);
        return $this->render('edit.html.twig', [
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
    public function save(Request $request, Commons $commons, Flickr $flickr, $commonsTitle = '', $flickrId = '')
    {
        $requestParams = $request->request->all();

        if (isset($requestParams['commons']['upload']) && $flickrId) {
            // Save new Commons photo.
            $uploaded = $commons->upload(
                $flickrId,
                $flickr,
                $requestParams['commons']['title'],
                $requestParams['commons']['page_text']
            );
            $commonsTitle = 'File:'.$uploaded['filename'];
        } elseif (!empty($requestParams['commons']['comment'])) {
            // Save existing Commons photo.
            $commons->savePage(
                $commonsTitle,
                $requestParams['commons']['page_text'],
                $requestParams['commons']['comment']
            );
        }
        if (!empty($flickrId) && isset($requestParams['flickr']['title'])) {
            $flickr->save($flickrId, $requestParams['flickr']);
        }

        // If we have both, redirect to both.
        if ($commonsTitle && $flickrId) {
            return $this->redirectToRoute('editBoth', [
                'commonsTitle' => 'File:'.$uploaded['filename'],
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
}
