(function() {

    function addLink() {
        let ids = [];
        document.querySelectorAll('.photo-list-view a.title').forEach(anchor => {
            ids.push(anchor.href.match(/[0-9]+/)[0]);
        });

        const searchResultsHeader = document.querySelector('.search-results-header');
        if (ids.length > 0 && searchResultsHeader) {
            let flickrDashLink = document.querySelector('#flickrdash-dupes') || document.createElement('a');
            flickrDashLink.href = 'https://tools.wmflabs.org/flickrdash/flickr/dupes/' + ids.join('/');
            flickrDashLink.text = 'Compare in FlickrDash';
            flickrDashLink.classList.add('view-more-link');
            flickrDashLink.target = '_blank';
            flickrDashLink.title = 'Opens in new tab';
            flickrDashLink.id = 'flickrdash-dupes';
            console.log('Adding FlickrDash link');
            searchResultsHeader.append(flickrDashLink);
        }
    }

    function main() {
        if (window.flickrDashHasRun) {
            return;
        }
        window.flickrDashHasRun = true;
        console.info('FlickrDash extension loaded');

        var observer = new MutationObserver(mutations => {
            mutations.forEach( mutation => {
                mutation.addedNodes.forEach(e => {
                    if ( e.classList.contains('photo-list-photo-interaction') ) {
                        addLink();
                    }
                });
            });
        });
        var observerOptions = { childList: true, attributes: true, subtree: true };
        observer.observe(document.body, observerOptions);
    }

    main();
})();
