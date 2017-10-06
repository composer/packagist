document.getElementById('search_query_query').addEventListener('keydown', function (e) {
    if (e.keyCode === 13) {
        e.preventDefault();
    }
});

var searchParameters = {};

if (algoliaConfig.tags) {
    searchParameters.disjunctiveFacets = ['tags'];
    searchParameters.disjunctiveFacetsRefinements = {
        tags: algoliaConfig.tags,
    };
}

if (algoliaConfig.type) {
    searchParameters.hierarchicalFacets = [{attributes: ['type'], name: 'type'}];
    searchParameters.hierarchicalFacetsRefinements = {
        type: [algoliaConfig.type],
    };
}

var search = instantsearch({
    appId: algoliaConfig.app_id,
    apiKey: algoliaConfig.search_key,
    indexName: algoliaConfig.index_name,
    urlSync: {
        trackedParameters: ['query', 'attribute:*', 'page']
    },
    searchFunction: function(helper) {
        var searchResults = $('#search-container');

        if (helper.state.query === ''
            && helper.state.hierarchicalFacetsRefinements.type === undefined
            && (helper.state.disjunctiveFacetsRefinements.tags === undefined || helper.state.disjunctiveFacetsRefinements.tags.length === 0)
            && algoliaConfig.tags.length === 0
            && algoliaConfig.type.length === 0
        ) {
            searchResults.addClass('hidden');
        } else {
            searchResults.removeClass('hidden');
        }

        helper.search();
    },
    searchParameters: searchParameters
});

search.addWidget(
    instantsearch.widgets.searchBox({
        container: '#search_query_query',
        magnifier: false,
        reset: false,
        wrapInput: false,
        autofocus: true
    })
);

search.addWidget(
    instantsearch.widgets.hits({
        container: '.search-list',
        transformData: function (hit) {
            if (hit.type === 'virtual-package') {
                hit.virtual = true;
            }

            return hit;
        },
        templates: {
            empty: 'No packages found.',
            item: `
<div data-url="/packages/{{ name }}" class="col-xs-12 package-item">
    <div class="row">
        <div class="col-sm-9 col-lg-10">
            <p class="pull-right language">{{ language }}</p>
            <h4 class="font-bold">
                <a href="/packages/{{ name }}" tabindex="2">{{{ _highlightResult.name.value }}}</a>
                {{#virtual}}
                    <small>(Virtual Package)</small>
                {{/virtual}}
            </h4>

            <p>{{{ _highlightResult.description.value }}}</p>

            {{#abandoned}}
            <p class="abandoned">
                <i class="glyphicon glyphicon-exclamation-sign"></i> Abandoned!
                {{#replacementPackage}}
                    See <a href="/packages/{{ replacementPackage }}">{{ replacementPackage }}</a>
                {{/replacementPackage}}
            </p>
            {{/abandoned}}
        </div>

        <div class="col-sm-3 col-lg-2">
            {{#meta}}
                <p class="metadata">
                    <span class="metadata-block"><i class="glyphicon glyphicon-arrow-down"></i> {{ meta.downloads_formatted }}</span>
                    <span class="metadata-block"><i class="glyphicon glyphicon-star"></i> {{ meta.favers_formatted }}</span>
                </p>
            {{/meta}}
        </div>
    </div>
</div>
`
        },
        cssClasses: {
            root: 'packages',
            item: 'row'
        }
    })
);

search.addWidget(
    instantsearch.widgets.pagination({
        container: '.pagination',
        maxPages: 200,
        scrollTo: false,
        showFirstLast: false,
    })
);

search.addWidget(
    instantsearch.widgets.currentRefinedValues({
        container: '.search-facets-active-filters',
        clearAll: 'before',
        clearsQuery: false,
        cssClasses: {
            clearAll: 'pull-right'
        },
        templates: {
            header: 'Active filters',
            item: function (filter) {
                if ('tags' == filter.attributeName) {
                    return 'tag: ' + filter.name
                } else {
                    return filter.attributeName + ': ' + filter.name
                }
            }
        },
        onlyListedAttributes: true,
    })
);

search.addWidget(
    instantsearch.widgets.menu({
        container: '.search-facets-type',
        attributeName: 'type',
        limit: 15,
        showMore: true,
        templates: {
            header: 'Package type'
        }
    })
);

search.addWidget(
    instantsearch.widgets.refinementList({
        container: '.search-facets-tags',
        attributeName: 'tags',
        limit: 15,
        showMore: true,
        templates: {
            header: 'Tags'
        },
        searchForFacetValues:true
    })
);

search.start();

if (algoliaConfig.tags.length || algoliaConfig.type.length) {
    search.helper.once('change', function (e) {
        window.history.replaceState(null, 'title', window.location.pathname);
    });
}
