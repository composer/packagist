var search = instantsearch({
    appId: algoliaConfig.app_id,
    apiKey: algoliaConfig.search_key,
    indexName: algoliaConfig.index_name,
    urlSync: true,
    searchFunction: function(helper) {
        var searchResults = $('#search-container');
        if (helper.state.query === '') {
            searchResults.addClass('hidden');
        } else {
            helper.search();
            searchResults.removeClass('hidden');
        }
    }
});

search.addWidget(
    instantsearch.widgets.searchBox({
        container: '#search_query_query',
        magnifier: false,
        reset: false,
        wrapInput: false,
        autofocus: true,
        //queryHook: function (query, search) {
        //    search(query);
        //}
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
  instantsearch.widgets.menu({
    container: '.search-facets-type',
    attributeName: 'type',
    limit: 15,
    templates: {
      header: 'Package type'
    }
  })
);

search.addWidget(
  instantsearch.widgets.menu({
    container: '.search-facets-tags',
    attributeName: 'tags',
    limit: 20,
    templates: {
      header: 'Tags'
    }
  })
);

search.start();
