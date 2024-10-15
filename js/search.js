import algoliasearch from 'algoliasearch';
import instantsearch from 'instantsearch.js';
import { connectSearchBox } from 'instantsearch.js/es/connectors';
import { hits, pagination, currentRefinements, menu, refinementList} from "instantsearch.js/es/widgets";

// avoid reflective XSS issues
if (decodeURI(location.search).match(/[<>]/)) {
    location.replace(location.origin + location.pathname);
}

let searchThrottle = null;
const search = instantsearch({
    indexName: algoliaConfig.index_name,
    searchClient: algoliasearch(algoliaConfig.app_id, algoliaConfig.search_key),
    routing: {
        stateMapping: {
            stateToRoute: function (uiState) {
                const state = uiState[algoliaConfig.index_name];
                return {
                    query: state.query && state.query.replace(new RegExp('([^\\s])--', 'g'), '$1-'),
                    type: state.menu && state.menu.type,
                    tags: state.refinementList && state.refinementList.tags && state.refinementList.tags.join('~'),
                    page: state.page,
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
                    [algoliaConfig.index_name]: {
                        query: routeState.query || '',
                        menu: {
                            type: routeState.type
                        },
                        refinementList: {
                            tags: routeState.tags && routeState.tags.replace(/[\s-]+/g, ' ').split('~'),
                        },
                        page: routeState.page
                    }
                }
            },
        },
    },
    onStateChange: function({uiState, setUiState}) {
        const searchResults = document.querySelector('#search-container');
        const state = uiState[algoliaConfig.index_name];

        if (state.query === ''
            && state.hierarchicalFacetsRefinements.type === undefined
            && (state.disjunctiveFacetsRefinements.tags === undefined || state.disjunctiveFacetsRefinements.tags.length === 0)
        ) {
            searchResults.classList.add('hidden');
            return;
        } else {
            searchResults.classList.remove('hidden');
        }

        if (searchThrottle) {
            clearTimeout(searchThrottle);
        }

        // force focus to prevent algolia from updating the search field input with the modified value
        if (state.query.match(/-/)) {
            document.getElementById('search_query_query').focus();
        }

        if (state.query.match(/^PKSA-.{14}$/) || state.query.match(/^GHSA-.{14}$/) || state.query.match(/^CVE-\d{4}-\d+$/)) {
            document.location.href = "/security-advisories/" + state.query;
        }

        state.query = state.query.replace(new RegExp('([^\\s])-', 'g'), '$1--');

        searchThrottle = setTimeout(() => {
            setUiState({[algoliaConfig.index_name]: state});
        }, 300);
    },
});

const renderSearchBox = (renderOptions, isFirstRender) => {
    const { query, refine, clear, isSearchStalled, widgetParams } = renderOptions;
    const input = document.querySelector('#search_query_query');

    // register events on the first render
    if (isFirstRender) {
        // focus the search on the homepage and explore
        if(location.pathname === "/" || location.pathname === "/explore/") {
            input.focus();
        }

        // trigger search on input change
        input.addEventListener('input', event => {
            refine(event.target.value);
        });

        // prevent form submission
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        // clear search on escape
        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                clear();
            }
        });

        // focus search on / but not when typing in the input
        document.addEventListener('keydown', event => {
            if (event.key !== '/' || document.activeElement === input) {
                return;
            }

            input.focus();
            event.preventDefault();
        });
    }

    input.value = query;
};

const customSearchBox = connectSearchBox(renderSearchBox);

search.addWidgets([
    customSearchBox({}),
    hits({
        container: '.search-list',
        transformItems: hits => hits.map(hit => {
            hit.url = '/packages/' + hit.name;
            if (hit.type === 'virtual-package') {
                hit.virtual = true;
                hit.url = '/providers/' + hit.name;
            }

            if (hit._highlightResult && hit._highlightResult.description.value && hit._highlightResult.description.value.length > 200) {
                hit._highlightResult.description.value = hit._highlightResult.description.value.substring(0, 200).replace(/<[a-z ]+$/, '');
            }

            hit.replacementPackageUrl = null

            if (hit.replacementPackage) {
                hit.replacementPackageUrl = hit.replacementPackage.indexOf('://') !== -1 ? hit.replacementPackage : ('/packages/' + hit.replacementPackage)
            }

            return hit;
        }),
        templates: {
            empty: 'No packages found.',
            item: `
<div data-url="{{ url }}" class="col-xs-12 package-item">
    <div class="row">
        <div class="col-sm-9 col-lg-10">
            <p class="pull-right language">{{ language }}</p>
            <h4 class="font-bold">
                <a href="{{ url }}" tabindex="2">{{#helpers.highlight}}{ "attribute": "name" }{{/helpers.highlight}}</a>
                {{#virtual}}
                    <small>(Virtual Package)</small>
                {{/virtual}}
            </h4>

            <p>{{{ _highlightResult.description.value }}}</p>

            {{#abandoned}}
            <p class="abandoned">
                <i class="glyphicon glyphicon-exclamation-sign"></i> Abandoned!
                {{#replacementPackage}}
                    See <a href="{{ replacementPackageUrl }}">{{ replacementPackage }}</a>
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
    }),
    pagination({
        container: '.pagination',
        maxPages: 200,
        scrollTo: document.getElementById('search_query_query'),
        showFirstLast: false,
    }),
    currentRefinements({
        container: '.search-facets-active-filters',
        clearAll: 'before',
        clearsQuery: false,
        cssClasses: {
            clearAll: 'pull-right'
        },
        templates: {
            header: 'Active filters',
            item: function (filter) {
                if ('tags' === filter.attributeName) {
                    return 'tag: ' + filter.name
                } else {
                    return filter.attributeName + ': ' + filter.name
                }
            }
        },
        onlyListedAttributes: true,
    }),
    menu({
        container: '.search-facets-type',
        attribute: 'type',
        limit: 15,
        showMore: true,
        templates: {
            header: 'Package type'
        }
    }),
    refinementList({
        container: '.search-facets-tags',
        attribute: 'tags',
        limit: 15,
        showMore: true,
        templates: {
            header: 'Tags'
        },
        searchForFacetValues:true
    }),
]);

search.start();
