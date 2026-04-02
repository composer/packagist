import algoliasearch from 'algoliasearch/lite';
import instantsearch from 'instantsearch.js';
import historyRouter from 'instantsearch.js/es/lib/routers/history';
import { connectSearchBox, connectCurrentRefinements } from 'instantsearch.js/es/connectors';
import { hits, pagination, clearRefinements, menu, refinementList, configure, panel } from 'instantsearch.js/es/widgets';

document.getElementById('search_query_query').addEventListener('keydown', function (e) {
    if (e.keyCode === 13) {
        e.preventDefault();
    }
});

// Add accessibility functionality:
// "Press '/' to focus the searchbar".
document.addEventListener('keydown', function (e) {
    if (e.key !== '/') {
        return;
    }
    var searchInput = document.getElementById('search_query_query');
    // Just ignore if we can't find the search input for some reason maybe we are on a page without it.
    if (!searchInput) {
        return;
    }
    // If we already have input focus ignore.
    if (document.activeElement.tagName === 'INPUT') {
        return;
    }
    searchInput.focus();
    // Prevent '/' being inserted on focus.
    e.preventDefault();
});

// avoid reflective XSS issues
if (decodeURI(location.search).match(/[<>]/)) {
    location.replace(location.origin + location.pathname);
}

var isSearchPage = location.pathname === '/search/';
var originalPathname = location.pathname;
var searchClient = algoliasearch(algoliaConfig.app_id, algoliaConfig.search_key);
var indexName = algoliaConfig.index_name;
var searchThrottle = null;

var customSearchClient = {
    ...searchClient,
    search: function (requests) {
        var transformedRequests = requests.map(function (req) {
            var params = Object.assign({}, req.params);
            if (params.query) {
                params.query = params.query.replace(/([^\s])-/g, '$1--');
            }
            return Object.assign({}, req, { params: params });
        });
        return new Promise(function (resolve, reject) {
            if (searchThrottle) { clearTimeout(searchThrottle); }
            searchThrottle = setTimeout(function () {
                searchClient.search(transformedRequests).then(resolve).catch(reject);
            }, 300);
        });
    },
};

// Show search container on initial load if URL has search params
var urlParams = new URLSearchParams(window.location.search);
var hasQuery = (urlParams.get('query') || '').trim() !== '' || (urlParams.get('q') || '').trim() !== '';
var hasFilters = urlParams.get('type') || urlParams.get('tags');
if (!isSearchPage && !hasQuery && hasFilters) {
    // Redirect to canonical /search/ URL with the filter params
    location.replace('/search/' + location.search);
} else if (hasQuery || (isSearchPage && hasFilters)) {
    document.querySelector('#search-container').classList.remove('hidden');
}

var opts = {
    indexName: indexName,
    searchClient: customSearchClient,
    onStateChange: function ({ uiState, setUiState }) {
        var indexState = uiState[indexName] || {};
        var searchResults = document.querySelector('#search-container');

        var hasQuery = indexState.query && indexState.query.trim() !== '';
        var hasFilters = (indexState.menu && indexState.menu.type)
            || (indexState.refinementList && indexState.refinementList.tags && indexState.refinementList.tags.length > 0);
        var hasSearch = hasQuery || (isSearchPage && hasFilters);

        if (!hasSearch) {
            searchResults.classList.add('hidden');
        } else {
            searchResults.classList.remove('hidden');
        }

        // Force focus to prevent algolia from overwriting input with transformed value
        if (indexState.query && indexState.query.match(/-/)) {
            document.getElementById('search_query_query').focus();
        }

        // Security advisory redirect
        if (indexState.query && (
            indexState.query.match(/^PKSA-.{14}$/) ||
            indexState.query.match(/^GHSA-.{14}$/) ||
            indexState.query.match(/^CVE-\d{4}-\d+$/)
        )) {
            document.location.href = "/security-advisories/" + indexState.query;
            return;
        }

        setUiState(uiState);
    },
    routing: {
        router: historyRouter({
            createURL: function ({ qsModule, routeState, location }) {
                var queryString = qsModule.stringify(routeState);
                var protocol = location.protocol;
                var hostname = location.hostname;
                var port = location.port ? ':' + location.port : '';
                // Use /search/ as base path when there are search params, otherwise restore original path
                var pathname = queryString ? '/search/' : originalPathname;
                return protocol + '//' + hostname + port + pathname + (queryString ? '?' + queryString : '') + location.hash;
            },
        }),
        stateMapping: {
            stateToRoute: function (uiState) {
                var indexUiState = uiState[indexName] || {};
                return {
                    query: indexUiState.query && indexUiState.query.replace(/([^\s])--/g, '$1-'),
                    type: indexUiState.menu && indexUiState.menu.type,
                    tags: indexUiState.refinementList && indexUiState.refinementList.tags && indexUiState.refinementList.tags.join('~'),
                    page: indexUiState.page,
                };
            },
            routeToState: function (routeState) {
                if (routeState.q) {
                    routeState.query = routeState.q;
                }

                var hasQuery = routeState.query && routeState.query.trim() !== '';
                var hasFilters = (routeState.type && routeState.type !== '')
                    || (routeState.tags && routeState.tags !== '');
                if (!hasQuery && !(isSearchPage && hasFilters)) {
                    return { [indexName]: {} };
                }

                return {
                    [indexName]: {
                        query: routeState.query || '',
                        menu: {
                            type: routeState.type
                        },
                        refinementList: {
                            tags: routeState.tags && routeState.tags.replace(/[\s-]+/g, ' ').split('~'),
                        },
                        page: routeState.page,
                    }
                };
            },
        },
    },
};

// If the search does not work (for example if the environment does not have
// algolia configured), we don't want to break the entire page, so we wrap it in
// a try/catch.
var search;
try {
    search = instantsearch(opts);
} catch (e) {
    console.error('Error initializing search', e);
    // We create a dummy search object with a no-op addWidgets and start function
    // to avoid errors in the rest of the code.
    search = {
        addWidgets: function() {},
        start: function() {},
        on: function() {},
    };
}

var customSearchBox = connectSearchBox(function (renderOptions, isFirstRender) {
    var query = renderOptions.query;
    var refine = renderOptions.refine;
    var input = document.getElementById('search_query_query');

    if (isFirstRender) {
        input.addEventListener('input', function (e) { refine(e.target.value); });
        if (location.pathname === '/' || location.pathname === '/explore/') {
            input.focus();
        }
    }

    // Only sync value when input is NOT focused — prevents cursor jumping
    if (document.activeElement !== input && input.value !== query) {
        input.value = query;
    }
});

var customCurrentRefinements = connectCurrentRefinements(function (renderOptions) {
    var items = renderOptions.items;
    var refine = renderOptions.refine;
    var container = document.querySelector('.search-facets-active-filters');
    var wrapper = document.querySelector('.search-facets-active-filters-wrapper');

    if (wrapper) {
        wrapper.style.display = items.length > 0 ? '' : 'none';
    }

    var html = '';
    items.forEach(function (item) {
        var label = item.attribute === 'tags' ? 'tag' : item.attribute;
        item.refinements.forEach(function (refinement) {
            html += '<span class="active-filter-item">'
                + '<span class="active-filter-label">' + label + ':</span> '
                + '<span class="active-filter-value">' + refinement.label + '</span>'
                + '<button class="active-filter-remove" data-attribute="' + item.attribute + '" data-value="' + refinement.label + '">&times;</button>'
                + '</span>';
        });
    });
    container.innerHTML = html;

    container.querySelectorAll('.active-filter-remove').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var attribute = btn.getAttribute('data-attribute');
            var value = btn.getAttribute('data-value');
            items.forEach(function (item) {
                if (item.attribute === attribute) {
                    item.refinements.forEach(function (refinement) {
                        if (refinement.label === value) {
                            refine(refinement);
                        }
                    });
                }
            });
        });
    });
});

var panelMenu = panel({ templates: { header: function () { return 'Package type'; } } })(menu);
var panelRefinementList = panel({
    templates: { header: function () { return 'Tags'; } },
    hidden: function (_ref) { return _ref.items.length === 0; },
})(refinementList);

search.addWidgets([
    customSearchBox({}),

    hits({
        container: '.search-list',
        transformItems: function (items) {
            return items.map(function (hit) {
                hit.url = '/packages/' + hit.name;
                if (hit.type === 'virtual-package') {
                    hit.virtual = true;
                    hit.url = '/providers/' + hit.name;
                }

                if (hit._highlightResult && hit._highlightResult.description && hit._highlightResult.description.value && hit._highlightResult.description.value.length > 200) {
                    hit._highlightResult.description.value = hit._highlightResult.description.value.substring(0, 200).replace(/<[a-z ]+$/, '');
                }

                hit.replacementPackageUrl = null;
                if (hit.replacementPackage) {
                    hit.replacementPackageUrl = hit.replacementPackage.indexOf('://') !== -1 ? hit.replacementPackage : ('/packages/' + hit.replacementPackage);
                }

                return hit;
            });
        },
        templates: {
            empty: function () { return 'No packages found.'; },
            item: function (hit) {
                var abandonedHtml = '';
                if (hit.abandoned) {
                    var replacementHtml = '';
                    if (hit.replacementPackage) {
                        replacementHtml = ` See <a href="${hit.replacementPackageUrl}" rel="nofollow noindex">${hit.replacementPackage}</a>`;
                    }
                    abandonedHtml = `<p class="abandoned"><i class="glyphicon glyphicon-exclamation-sign"></i> Abandoned!${replacementHtml}</p>`;
                }

                var virtualHtml = hit.virtual ? '<small>(Virtual Package)</small>' : '';
                var extensionHtml = hit.extension ? '<span title="PIE installable extension package">🥧</span>' : '';

                var metaHtml = '';
                if (hit.meta) {
                    metaHtml = `<p class="metadata">
                        <span class="metadata-block"><i class="glyphicon glyphicon-download"></i> ${hit.meta.downloads_formatted}</span>
                        <span class="metadata-block"><i class="glyphicon glyphicon-star"></i> ${hit.meta.favers_formatted}</span>
                    </p>`;
                }

                var nameHighlight = hit._highlightResult && hit._highlightResult.name ? hit._highlightResult.name.value : hit.name;
                var descHighlight = hit._highlightResult && hit._highlightResult.description ? hit._highlightResult.description.value : (hit.description || '');

                return `<div data-url="${hit.url}" class="col-xs-12 package-item">
                    <div class="row">
                        <div class="col-sm-9 col-lg-10">
                            <p class="pull-right language">${hit.language || ''}</p>
                            <h4 class="font-bold">
                                <a href="${hit.url}" tabindex="2" rel="nofollow noindex">${nameHighlight}</a>${extensionHtml}
                                ${virtualHtml}
                            </h4>
                            <p>${descHighlight}</p>
                            ${abandonedHtml}
                        </div>
                        <div class="col-sm-3 col-lg-2">
                            ${metaHtml}
                        </div>
                    </div>
                </div>`;
            },
        },
        cssClasses: {
            root: 'packages',
            item: 'row',
        },
    }),

    pagination({
        container: '.pagination',
        totalPages: 20,
        scrollTo: '#search_query_query',
        showFirst: false,
        showLast: false,
    }),

    clearRefinements({
        container: '.search-facets-clear-all',
        templates: {
            resetLabel: function () { return 'Clear all'; },
        },
    }),

    customCurrentRefinements({}),

    panelMenu({
        container: '.search-facets-type',
        attribute: 'type',
        limit: 15,
    }),

    panelRefinementList({
        container: '.search-facets-tags',
        attribute: 'tags',
        limit: 15,
        showMore: true,
        searchable: true,
    }),
]);

if (location.href.match(/\/extensions/)) {
    search.addWidgets([configure({ filters: 'extension = 1' })]);
}

search.on('render', function () {
    document.querySelectorAll('#search-container a[href]').forEach(function (link) {
        if (!link.getAttribute('rel')) {
            link.setAttribute('rel', 'nofollow noindex');
        }
    });
});

search.start();
