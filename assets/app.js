// Workaround for OOUI and Webpack not loading things to global scope.
global.OO = OO;

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
    $.i18n().load( messagesToLoadApp );

    console.log($.i18n( 'flickr-duplicates-search' ));
} );


$(function () {

    var searchButton = new OO.ui.ButtonWidget( { label: $.i18n( 'flickr-duplicates-search' ) } ),
        dupesContainer = $("#flickr-dupes"),
        progressBarField;

    dupesContainer.append(searchButton.$element);

    var progressBar = new OO.ui.ProgressBarWidget();


    searchButton.on( 'click', function () {

        progressBarField = new OO.ui.FieldLayout(progressBar, {
            //align: 'top',
            label: $.i18n('flickr-dupes-progress')
        });
        dupesContainer.append(progressBarField.$element);

        getNextDuplicate( 1 );

        // $.getJSON(dupesContainer.data('info-url'), function ( info ) {
        //     console.log(info);
        //     for ( var page = 1; page < info.pages; page++ ) {
        //         console.log(page);
        //         progressBar.setProgress( ( page / info.pages ) * 100 );
        //         jQuery.ajax({
        //             url: dupesContainer.data('info-url') + '/' + page,
        //             type: 'get',
        //             dataType: 'json',
        //             success: function (data) {
        //                 console.log(data);
        //             },
        //             //async: false,
        //         } );
        //     }
        // });
    });

    function getNextDuplicate( pageNum ) {
        $.getJSON(dupesContainer.data('info-url')+'/'+pageNum, function ( info ) {
            console.log(info);
            if (info.url) {
                console.log("found!", info);
                // @TODO redirect to URL.
            } else {
                console.log(pageNum, info.pages, ( pageNum / info.pages ) * 100 );
                progressBar.setProgress( ( pageNum / info.pages ) * 100 );
                getNextDuplicate( pageNum + 1 );
            }
        });
    }

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
            flickrAccuracy = 16,
            $flickrLatInput = $(":input[name='flickr[latitude]']");
        if ($flickrLatInput.length === 1) {
            flickrLatWidget = OO.ui.infuse($flickrLatInput.parents('.oo-ui-widget'));
            lat = flickrLatWidget.getValue();
            flickrLonWIdget = OO.ui.infuse($(":input[name='flickr[longitude]']").parents('.oo-ui-widget'));
            lon = flickrLonWIdget.getValue();
            flickrAccuracyWidget = OO.ui.infuse($(":input[name='flickr[accuracy]']").parents('.oo-ui-widget'));
            flickrAccuracy = flickrAccuracyWidget.getValue();
        }
        var mapOptions = {center: [lat, lon], zoom: flickrAccuracy};
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
            if (map.hasLayer(marker)) {
                map.removeLayer(marker);
            }
            var x = require( 'oojs-ui/dist/themes/wikimediaui/images/icons/mapPin.svg' );
            var icon = L.icon({iconUrl: x });
            marker = L.marker(latLng, { clickable:true, draggable:true, icon: icon });
            marker.on({
                add: recordNewCoords,
                dragend: recordNewCoords
            });
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

    var $originalTag = $( ':input[name="flickr[tags]"]' ).parents( '.oo-ui-widget' );
    if ($originalTag.length === 1) {
        var originalTagWidget = OO.ui.infuse($originalTag);
        console.log(originalTagWidget.data);

        items = $.map( originalTagWidget.data, function ( tag ) {
            return new OO.ui.MenuOptionWidget( { data: tag.raw } );
        } );
        console.log(items);
        var replacement = new OO.ui.MenuTagMultiselectWidget({
            allowArbitrary: true,
            menu: { items: items }
        });
//        replacement.addItems(originalTagWidget.data)
        console.log(replacement);
        $originalTag.after(replacement.$element);
    }

});
