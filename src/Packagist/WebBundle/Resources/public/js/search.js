document.getElementById('search_query_query').addEventListener('keydown', function (e) {
    if (e.keyCode === 13) {
        e.preventDefault();
    }
});

var searchParameters = {};

if (decodeURI(location.search).match(/[<>]/)) {
    location.replace(location.pathname);
}

var searchThrottle = null;
var search = instantsearch({
    appId: algoliaConfig.app_id,
    apiKey: algoliaConfig.search_key,
    indexName: algoliaConfig.index_name,
    routing: {
        stateMapping: {
            stateToRoute: function (uiState) {
                return {
                    query: uiState.query,
                    type: uiState.menu && uiState.menu.type,
                    tags: uiState.refinementList && uiState.refinementList.tags && uiState.refinementList.tags.join('~'),
                    page: uiState.page,
                };
            },
            routeToState: function (routeState) {
                if (routeState.q) {
                    routeState.query = routeState.q;
                }

                if (
                    (routeState.query === undefined || routeState.query === '')
                    && (routeState.type === undefined || routeState.type === '')
                    && (routeState.tags === undefined || routeState.tags === '')
                ) {
                    return {};
                }

                return {
                    query: routeState.query || '',
                    menu: {
                        type: routeState.type
                    },
                    refinementList: {
                        tags: routeState.tags && routeState.tags.split('~'),
                    },
                    page: routeState.page
                };
            },
        },
    },
    searchFunction: function(helper) {
        var searchResults = $('#search-container');

        if (helper.state.query === ''
            && helper.state.hierarchicalFacetsRefinements.type === undefined
            && (helper.state.disjunctiveFacetsRefinements.tags === undefined || helper.state.disjunctiveFacetsRefinements.tags.length === 0)
        ) {
            searchResults.addClass('hidden');
        } else {
            searchResults.removeClass('hidden');
        }

        if (searchThrottle) {
            clearTimeout(searchThrottle);
        }

        searchThrottle = setTimeout(function () {
            helper.search();
        }, 300);
    },
    searchParameters: searchParameters
});

var autofocus = false;
if (location.pathname == "/" || location.pathname == "/app_dev.php/") {
    autofocus = true;
}
search.addWidget(
    instantsearch.widgets.searchBox({
        container: '#search_query_query',
        magnifier: false,
        reset: false,
        wrapInput: false,
        autofocus: autofocus
    })
);

search.addWidget(
    instantsearch.widgets.hits({
        container: '.search-list',
        transformData: function (hit) {
            hit.url = '/packages/' + hit.name;
            if (hit.type === 'virtual-package') {
                hit.virtual = true;
                hit.url = '/providers/' + hit.name;
            }

            if (hit._highlightResult && hit._highlightResult.description.value && hit._highlightResult.description.value.length > 200) {
                hit._highlightResult.description.value = hit._highlightResult.description.value.substring(0, 200).replace(/<[a-z ]+$/, '');
            }

            return hit;
        },
        templates: {
            empty: 'No packages found.',
            item: `
<div data-url="{{ url }}" class="col-xs-12 package-item">
    <div class="row">
        <div class="col-sm-9 col-lg-10">
            <p class="pull-right language">{{ language }}</p>
            <h4 class="font-bold">
                <a href="{{ url }}" tabindex="2">{{{ _highlightResult.name.value }}}</a>
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
                    <span class="metadata-block"><i class="glyphicon glyphicon-download"></i> {{ meta.downloads_formatted }}</span>
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
        scrollTo: document.getElementById('search_query_query'),
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
