/**
 * @class
 * @constructor
 */
FlickrTagWidget = function flickrTagWidget ( config ) {
    config = $.extend( {
        allowArbitrary: true
    }, config );
    FlickrTagWidget.parent.call( this, config );

    OO.ui.mixin.PendingElement.call( this, $.extend( {}, config, { $pending: this.$handle } ) );

    this.connect( this, {
        change: this.onMultiselectChange
    } );
    this.onMultiselectChange();

    this.input.connect( this, {
        change: OO.ui.debounce( this.onInputChangeDebounced, 800 )
    } );
};

OO.inheritClass( FlickrTagWidget, OO.ui.MenuTagMultiselectWidget );
OO.mixinClass( FlickrTagWidget, OO.ui.mixin.PendingElement );

FlickrTagWidget.prototype.onInputChangeDebounced = function () {
    var value = this.input.getValue();
    if (value === '' || value === []) {
        return;
    }
    this.pushPending();
    $.getJSON( appConfig.baseUrl + 'tags?q=' + value, this.searchCallback.bind( this ) );
};

FlickrTagWidget.prototype.searchCallback = function ( data ) {
    var menuOption, searchResult, i,
        options = [];
    require( './TagMenuOptionWidget' );
    for (i = 0; i < data.length; i++) {
        searchResult = data[i];
        menuOption = new TagMenuOptionWidget ( searchResult );
        menuOption.$element.data( 'tag-info', searchResult );
        options.push( menuOption );
    }
    this.menu.clearItems();
    this.menu.addItems( options );
    this.menu.toggle( true );
    this.popPending();
};

FlickrTagWidget.prototype.onMenuChoose = function ( menuItem ) {
    FlickrTagWidget.parent.prototype.onMenuChoose.apply( this, arguments );
    var searchResult = menuItem.$element.data( 'tag-info' );
    if ( searchResult.itemid !== undefined ) {
        this.addTag( 'wikidata:id=' + searchResult.itemid );
        // See if this Wikidata item is linked to a Commons category.
        this.pushPending();
        $.ajax( {
            url: 'https://www.wikidata.org/w/api.php',
            dataType: 'jsonp',
            data: {
                action: 'wbgetclaims',
                format: 'json',
                entity: searchResult.itemid,
            }
        } ).done( this.commonsCategoryCallback.bind( this ) );
    }
};

FlickrTagWidget.prototype.commonsCategoryCallback = function ( data ) {
    var commonsPageTextWidget, commonsCommentWidget, commonsCatName, newPageText,
        commonsCatProp = 'P373';
    if ( data.claims !== undefined && data.claims[ commonsCatProp ] !== undefined ) {
        commonsCatName = data.claims[ commonsCatProp ][ 0 ].mainsnak.datavalue.value;
        commonsPageTextWidget = OO.ui.infuse( $( '#commons-page-text-widget' ) );
        newPageText = commonsPageTextWidget.getValue() + "\n[[Category:" + commonsCatName + "]]";
        commonsPageTextWidget.setValue( newPageText );
        commonsCommentWidget = OO.ui.infuse( $( '#commons-comment-widget' ) );
        commonsCommentWidget.setValue( commonsCommentWidget.getValue() + " Categorize." );
    }
    this.popPending();
};

FlickrTagWidget.prototype.onMultiselectChange = function () {
    var flickrInput = $( ':input[name="flickr[tags]"]' );
    var items = this.getItems();
    var tagsForFlickr = [];
    for ( i = 0; i < items.length; i++ ) {
        var tag = items[i].data;
        if (tag.includes(' ' ) ) {
            tag = ' "'+tag+'"';
        }
        tagsForFlickr.push( tag );
    }
    flickrInput.val( tagsForFlickr.join( ' ' ) );
};
