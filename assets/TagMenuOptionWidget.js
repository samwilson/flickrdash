/**
 * @class
 * @constructor
 * @param {Object} [config] Configuration options.
 * @cfg {jQuery|string} [description=''] Search result description
 */
TagMenuOptionWidget = function tagMenuOptionWidget( config ) {
    var $description, url;
    if ( config.label === undefined && config.data !== undefined ) {
        config.label = config.data;
    }

    if ( config.itemid !== undefined ) {
        url = 'https://www.wikidata.org/wiki/'+config.itemid;
        config.label = new OO.ui.HtmlSnippet('<a href="'+url+'" target="_blank">'+config.label+'</a>');
    }

    TagMenuOptionWidget.super.call( this, config );

    // Description.
    $description = $( '<span>' )
        .addClass( 'description' )
        .append( $( '<bdi>' ).text( config.description || '' ) );

    if (config.aliases) {
        $description.append( ' <span class="aliases">' + $.i18n('wikidata-aliases') + ' ' + config.aliases.join(' &middot; ') );
    }

    this.$element.append( $description );
};

OO.inheritClass( TagMenuOptionWidget , OO.ui.MenuOptionWidget );
