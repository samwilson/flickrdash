// Workaround for OOUI and Webpack not loading things to global scope.
global.OO = OO;

global.App = {};
require('./App.FlickrDupes');

// Load i18n message files.
$( function () {
    var lang = $( 'html' ).attr( 'lang' ),
        messagesToLoadUls = {},
        messagesToLoadApp = {};
    messagesToLoadUls[ lang ] = appConfig.assetsPath + '/i18n/jquery.uls/' + lang + '.json';
    messagesToLoadApp[ lang ] = appConfig.assetsPath + '/i18n/app/' + lang + '.json';
    if ( lang !== 'en' ) {
        // Also load English files for fallback.
        messagesToLoadUls.en = appConfig.assetsPath + '/i18n/jquery.uls/en.json';
        messagesToLoadApp.en = appConfig.assetsPath + '/i18n/app/en.json';
    }
    $.i18n().locale = lang;
    $.i18n().load( messagesToLoadUls );
    $.i18n().load( messagesToLoadApp ).then( App.FlickrDupes.init );
} );

$(function () {

    // var searchButtonElement = $("#flickr-dupes-search-button");
    // if (searchButtonElement.length === 1) {
    //     console.log(searchButtonElement);
    //     var searchButton = OO.ui.infuse(searchButtonElement);
    //     console.log(searchButton);
    // }

    /**
     * Set up the map.
     */
    if ( $( '#map' ).length === 1 ) {
        var flickrLatWidget, flickrLonWIdget, flickrAccuracyWidget,
            lat = 0,
            lon = 0,
            zoom = 1,
            $flickrLatInput = $(":input[name='flickr[latitude]']");
        if ($flickrLatInput.length === 1) {
            flickrLatWidget = OO.ui.infuse($flickrLatInput.parents('.oo-ui-widget'));
            lat = flickrLatWidget.getValue();
            flickrLonWIdget = OO.ui.infuse($(":input[name='flickr[longitude]']").parents('.oo-ui-widget'));
            lon = flickrLonWIdget.getValue();
            flickrAccuracyWidget = OO.ui.infuse($(":input[name='flickr[accuracy]']").parents('.oo-ui-widget'));
            zoom = flickrAccuracyWidget.getValue();
        }
        var mapOptions = {center: [lat, lon], zoom: zoom};
        var map = L.map( 'map', mapOptions ),
            marker;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        if ( lat && lon ) {
            addMarker({lat: lat, lng: lon});
        }
        map.on('click', function(e) {
            addMarker(e.latlng);
        });
        // Add a marker at the specified location.
        function addMarker(latLng) {
            var mapPinUrlPath, icon;
            if (map.hasLayer(marker)) {
                map.removeLayer(marker);
            }
            mapPinUrlPath = require('../node_modules/oojs-ui/dist/themes/wikimediaui/images/icons/mapPin.svg');
            icon = L.icon({iconUrl: appConfig.baseUrl + 'assets/' + mapPinUrlPath, iconAnchor: [10, 20]});
            marker = L.marker(latLng, { clickable:true, draggable:true, icon: icon });
            marker.on({add: recordNewCoords, dragend: recordNewCoords});
            marker.addTo(map);
            map.panTo(marker.getLatLng());
        }
        function recordNewCoords(e) {
            var locationTemplate, pageText,
                locationTplPattern = /{{location\|[-.0-9]+\|[-.0-9]+[^}]*}}/i,
                newLat = Math.round( marker.getLatLng().lat * 100000 ) / 100000,
                newLon = Math.round( marker.getLatLng().lng * 100000 ) / 100000;
            // FLickr.
            if (flickrLatWidget) {
                flickrLatWidget.setValue(newLat);
                flickrLonWIdget.setValue(newLon);
                flickrAccuracyWidget.setValue(map.getZoom());
            }
            // Commons.
            commonsPageTextWidget = OO.ui.infuse($('#commons-page-text-widget'));
            locationTemplate = '{{location|'+newLat+'|'+newLon+'}}';
            pageText = commonsPageTextWidget.getValue();
            if ( pageText.match( locationTplPattern ) ) {
                pageText = pageText.replace( locationTplPattern, locationTemplate );
            } else {
                pageText = pageText + '\n\n' + locationTemplate;
            }
            commonsPageTextWidget.setValue( pageText );
        }
    }

    /**
     * Enable/disable the Commons form for Flickr photos that are not on Commons yet.
     */
    var $commonsUploadElement = $('#commons-upload-widget');
    if ($commonsUploadElement.length === 1) {
        var commonsUploadWidget = OO.ui.infuse($commonsUploadElement),
            commonsTitleWidget = OO.ui.infuse($('#commons-title-widget')),
            commonsCaptionWidget = OO.ui.infuse($('#commons-caption-widget')),
            commonsPageTextWidget = OO.ui.infuse($('#commons-page-text-widget'));
        commonsTitleWidget.setDisabled(true);
        commonsCaptionWidget.setDisabled(true);
        commonsPageTextWidget.setDisabled(true);
        commonsUploadWidget.on('change', function() {
            commonsTitleWidget.setDisabled(!commonsUploadWidget.isSelected());
            commonsCaptionWidget.setDisabled(!commonsUploadWidget.isSelected());
            commonsPageTextWidget.setDisabled(!commonsUploadWidget.isSelected());
        } );
    }

    /**
     * Make the Commons edit summary required if any modification is made to the two Commons fields.
     */
    $( ':input[name^=commons]' ).on( 'change', function () {
        $( ':input[name="commons[comment]"]' ).prop( 'required', true );
    });

    /**
     * Tags.
     */
    var $tagWidgetElement = $( ':input[name="flickr[tags]"]' ).parents( '.oo-ui-widget' );
    if ( $tagWidgetElement.length === 1 ) {
        var tagWidget = OO.ui.infuse( $tagWidgetElement );
        var selected = [];
        tagWidget.data.forEach( function ( tag ) {
            selected.push( tag.raw );
        } );
        require('./FlickrTagWidget');
        var flickrTagWidget = new FlickrTagWidget( {
            selected: selected,
            name: 'foo'
        } );
        tagWidget.$element.after( flickrTagWidget.$element );
        tagWidget.$element.hide();
    }

});
