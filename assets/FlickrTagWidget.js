/**
 * @class
 * @constructor
 */
FlickrTagWidget = function flickrTagWidget ( config ) {
    config = $.extend( {
        allowArbitrary: true
    }, config );
    FlickrTagWidget.parent.call( this, config );

    this.connect( this, {
        change: this.onMultiselectChange
    } );
    this.onMultiselectChange();

    this.input.connect( this, {
        change: OO.ui.debounce( this.onInputChangeDebounced, 800 )
    } );
};

OO.inheritClass( FlickrTagWidget, OO.ui.MenuTagMultiselectWidget );

FlickrTagWidget.prototype.onInputChangeDebounced = function () {
    var value = this.input.getValue();
    if (value === '' || value === []) {
        return;
    }
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
};

FlickrTagWidget.prototype.onMenuChoose = function ( menuItem ) {
    FlickrTagWidget.parent.prototype.onMenuChoose.apply( this, arguments );
    var searchResult = menuItem.$element.data( 'tag-info' );
    if ( searchResult.itemid !== undefined ) {
        this.addTag( 'wikidata=' + searchResult.itemid );
    }
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
