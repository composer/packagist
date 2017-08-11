var search = instantsearch({
    appId: '6B7GQC987O',
    apiKey: 'a4b2a9870a8575c9e5c3d2dd00d8de95',
    indexName: 'packagist',
    urlSync: true
});

search.addWidget(
    instantsearch.widgets.searchBox({
        container: '#search_query_query',
        magnifier: false,
        reset: false,
        wrapInput: false
    })
);

search.addWidget(
    instantsearch.widgets.hits({
        container: '.search-list',
        templates: {
            empty: 'No packages found.',
            item: `
<div data-url="{{ packageUrl }}" class="col-xs-12 package-item">
    <div class="row">
        <div class="col-sm-9 col-lg-10">
            <p class="pull-right language">{{ language }}</p>
            <h4 class="font-bold">
                <a href="{{ packageUrl }}">{{ name }}</a>
                {{#virtual}}
                    <small>(Virtual Package)</small>
                {{/virtual}}
                {{#showAutoUpdateWarning}}
                    <small>(Not Auto-Updated)</small>
                {{/showAutoUpdateWarning}}
            </h4>

            <p>{{ description }}</p>

            {{#abandoned}}
            <p class="abandoned">
                <i class="glyphicon glyphicon-exclamation-sign"></i> Abandoned!
                {{#replacementPackage}}
                    See <a href="{{ replacementPackage.link }}">{{ replacementPackage.name }}</a>
                {{/replacementPackage}}
            </p>
            {{/abandoned}}
        </div>

        <div class="col-sm-3 col-lg-2">
            {{#meta}}
                <p class="metadata">
                    <span class="metadata-block"><i class="glyphicon glyphicon-arrow-down"></i> {{ meta.download_formatted.total }}</span>
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

search.start();
