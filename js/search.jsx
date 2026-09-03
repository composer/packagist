/** @jsx h */
/** @jsxFrag Fragment */
import { h, Fragment } from 'preact';
import instantsearch from 'instantsearch.js';
import { liteClient as algoliasearch } from 'algoliasearch/lite';
import historyRouter from 'instantsearch.js/es/lib/routers/history';
import { connectSearchBox, connectCurrentRefinements } from 'instantsearch.js/es/connectors';
import { hits, pagination, clearRefinements, menu, numericMenu, refinementList, configure, panel } from 'instantsearch.js/es/widgets';

// this file is imported by app.js ahead of every other module, so an unguarded lookup here would
// take out view.js, the tooltips and the bootstrap data-api handlers on any page without the search
var searchQueryInput = document.getElementById('search_query_query');
if (searchQueryInput) {
    searchQueryInput.addEventListener('keydown', function (e) {
        if (e.keyCode === 13) {
            e.preventDefault();
        }
    });
}

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
var hasFilters = urlParams.get('type') || urlParams.get('tags') || urlParams.get('license') || urlParams.get('released');
if (!isSearchPage && !hasQuery && hasFilters) {
    // Redirect to canonical /search/ URL with the filter params
    location.replace('/search/' + location.search);
} else if (hasQuery || (isSearchPage && hasFilters)) {
    document.querySelector('#search-container').classList.remove('d-none');
}

var opts = {
    indexName: indexName,
    searchClient: customSearchClient,
    onStateChange: function ({ uiState, setUiState }) {
        var indexState = uiState[indexName] || {};
        var searchResults = document.querySelector('#search-container');

        var hasQuery = indexState.query && indexState.query.trim() !== '';
        var refined = indexState.refinementList || {};
        var hasFilters = (indexState.menu && indexState.menu.type)
            || (refined.tags && refined.tags.length > 0)
            || (refined['meta.license'] && refined['meta.license'].length > 0)
            || (indexState.numericMenu && Object.keys(indexState.numericMenu).length > 0);
        var hasSearch = hasQuery || (isSearchPage && hasFilters);

        if (!hasSearch) {
            searchResults.classList.add('d-none');
        } else {
            searchResults.classList.remove('d-none');
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
                var refined = indexUiState.refinementList || {};
                return {
                    query: indexUiState.query && indexUiState.query.replace(/([^\s])--/g, '$1-'),
                    type: indexUiState.menu && indexUiState.menu.type,
                    tags: refined.tags && refined.tags.join('~'),
                    license: refined['meta.license'] && refined['meta.license'].join('~'),
                    released: indexUiState.numericMenu && indexUiState.numericMenu['meta.released_ts'],
                    page: indexUiState.page,
                };
            },
            routeToState: function (routeState) {
                if (routeState.q) {
                    routeState.query = routeState.q;
                }

                var hasQuery = routeState.query && routeState.query.trim() !== '';
                var hasFilters = (routeState.type && routeState.type !== '')
                    || (routeState.tags && routeState.tags !== '')
                    || (routeState.license && routeState.license !== '')
                    || (routeState.released && routeState.released !== '');
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
                            'meta.license': routeState.license && routeState.license.split('~'),
                        },
                        numericMenu: routeState.released ? { 'meta.released_ts': routeState.released } : undefined,
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

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (char) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char];
    });
}

var customCurrentRefinements = connectCurrentRefinements(function (renderOptions) {
    var items = renderOptions.items;
    var refine = renderOptions.refine;
    var container = document.querySelector('.search-facets-active-filters');
    var wrapper = document.querySelector('.search-facets-active-filters-wrapper');

    if (wrapper) {
        wrapper.style.display = items.length > 0 ? '' : 'none';
    }

    var attributeLabels = { tags: 'tag', 'meta.license': 'license' };
    var html = '';
    items.forEach(function (item) {
        var label = attributeLabels[item.attribute] || item.attribute;
        item.refinements.forEach(function (refinement) {
            html += '<span class="badge bg-primary active-filter-item">'
                + escapeHtml(label) + ': ' + escapeHtml(refinement.label)
                + '<button type="button" class="btn-close btn-close-white active-filter-remove" aria-label="Remove filter" data-attribute="' + escapeHtml(item.attribute) + '" data-value="' + escapeHtml(refinement.label) + '"></button>'
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
var panelLicense = panel({
    templates: { header: function () { return 'License'; } },
    hidden: function (_ref) { return _ref.items.length === 0; },
})(refinementList);
var panelReleased = panel({ templates: { header: function () { return 'Released'; } } })(numericMenu);

var releasedNow = Date.now();
function releasedStart(daysAgo) {
    return Math.floor((releasedNow - daysAgo * 86400000) / 1000);
}

function PackageHit(hit) {
    var nameHighlight = hit._highlightResult && hit._highlightResult.name ? hit._highlightResult.name.value : hit.name;
    var descHighlight = hit._highlightResult && hit._highlightResult.description ? hit._highlightResult.description.value : (hit.description || '');
    var license = hit.meta && hit.meta.license && hit.meta.license.length ? hit.meta.license.join(', ') : '';

    return (
        <div data-url={hit.url} class="col-12 package-item">
            <div class="row align-items-center">
                <div class="col-md-9 col-xl-10">
                    <p class="float-end language">{hit.language || ''}</p>
                    <h4 class="font-bold">
                        <a href={hit.url} tabindex="2" rel="nofollow noindex" dangerouslySetInnerHTML={{ __html: nameHighlight }} />
                        {hit.extension ? <span title="PIE installable extension package">🥧</span> : null}
                        {hit.virtual ? <Fragment>{' '}<small>(Virtual Package)</small></Fragment> : null}
                    </h4>
                    <p dangerouslySetInnerHTML={{ __html: descHighlight }} />
                    {hit.meta && hit.meta.release ? (
                        <p class="release-metadata">
                            <span class="release-metadata-block" title="Latest version">{hit.meta.release}</span>
                            {hit.meta.released ? (
                                <span class="release-metadata-block"><i class="bi bi-clock-fill" title="Release date" />{' '}{hit.meta.released}</span>
                            ) : null}
                            {license ? (
                                <span class="release-metadata-block"><i class="bi bi-c-circle" title="License" />{' '}{license}</span>
                            ) : null}
                        </p>
                    ) : null}
                    {hit.tags && hit.tags.length ? (
                        <p class="tags">
                            <i class="bi bi-tag-fill" title="Tags" />{' '}
                            {hit.tags.slice(0, 10).map(function (tag) {
                                return (
                                    <a key={tag} rel="nofollow noindex" href={'/search/?tags=' + encodeURIComponent(tag)}>{tag}</a>
                                );
                            })}
                        </p>
                    ) : null}
                    {hit.abandoned ? (
                        <p class="abandoned">
                            <i class="bi bi-exclamation-circle-fill" /> Abandoned!
                            {hit.replacementPackage ? (
                                <Fragment>{' '}See <a href={hit.replacementPackageUrl} rel="nofollow noindex">{hit.replacementPackage}</a></Fragment>
                            ) : null}
                        </p>
                    ) : null}
                </div>
                <div class="col-md-3 col-xl-2">
                    {hit.meta ? (
                        <p class="metadata">
                            <span class="metadata-block"><i class="bi bi-download" />{' '}{hit.meta.downloads_formatted}</span>
                            <span class="metadata-block"><i class="bi bi-star-fill" />{' '}{hit.meta.favers_formatted}</span>
                        </p>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

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
            item: function (hit) { return PackageHit(hit); },
        },
        cssClasses: {
            root: 'packages',
            item: 'row',
        },
    }),

    pagination({
        container: '.pagination-mount',
        totalPages: 20,
        scrollTo: '#search_query_query',
        showFirst: false,
        showLast: false,
        cssClasses: {
            list: 'pagination justify-content-center',
            item: 'page-item',
            link: 'page-link',
            selectedItem: 'active',
            disabledItem: 'disabled',
        },
    }),

    clearRefinements({
        container: '.search-facets-clear-all',
        templates: {
            resetLabel: function () { return 'Clear all'; },
        },
    }),

    customCurrentRefinements({ excludedAttributes: ['query', 'meta.released_ts'] }),

    panelMenu({
        container: '.search-facets-type',
        attribute: 'type',
        limit: 15,
    }),

    panelLicense({
        container: '.search-facets-license',
        attribute: 'meta.license',
        limit: 10,
        showMore: true,
        cssClasses: {
            checkbox: 'form-check-input',
        },
    }),

    panelReleased({
        container: '.search-facets-released',
        attribute: 'meta.released_ts',
        items: [
            { label: 'Any time' },
            { label: 'Past week', start: releasedStart(7) },
            { label: 'Past month', start: releasedStart(30) },
            { label: 'Past 3 months', start: releasedStart(90) },
            { label: 'Past year', start: releasedStart(365) },
        ],
    }),

    panelRefinementList({
        container: '.search-facets-tags',
        attribute: 'tags',
        limit: 15,
        showMore: true,
        searchable: true,
        cssClasses: {
            checkbox: 'form-check-input',
            searchableForm: 'input-group input-group-sm',
            searchableInput: 'form-control',
            searchableSubmit: 'search-facets-searchbox-btn',
            searchableReset: 'search-facets-searchbox-btn',
        },
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
